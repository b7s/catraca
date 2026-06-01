<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function count;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function implode;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

readonly class SecurityGate implements GateInterface
{
    public const array DEFAULT_RULES = [
        'hardcoded_secrets' => true,
        'sql_injection' => true,
        'command_injection' => true,
        'csrf_protection' => true,
        'path_traversal' => true,
        'insecure_deserialization' => true,
        'ssrf' => true,
        'tls_verification' => true,
        'insecure_rng' => true,
        'gitignore_sensitive' => true,
        'package_freshness' => true,
        'weak_cryptography' => true,
        'cors_config' => true,
        'npm_audit' => true,
    ];

    private const array SUB_CHECK_METHODS = [
        'hardcoded_secrets' => 'checkHardcodedSecrets',
        'sql_injection' => 'checkSqlInjection',
        'command_injection' => 'checkCommandInjection',
        'csrf_protection' => 'checkCsrf',
        'path_traversal' => 'checkPathTraversal',
        'insecure_deserialization' => 'checkInsecureDeserialization',
        'ssrf' => 'checkSsrf',
        'tls_verification' => 'checkTlsVerification',
        'insecure_rng' => 'checkInsecureRng',
        'gitignore_sensitive' => 'checkGitignore',
        'package_freshness' => 'checkPackageFreshness',
        'weak_cryptography' => 'checkWeakCryptography',
        'cors_config' => 'checkCorsConfig',
        'npm_audit' => 'checkNpmAudit',
    ];

    public function __construct(
        private SourcePathResolver $pathResolver = new SourcePathResolver,
    ) {}

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $rules = $this->getEnabledRules($baseline);
        $root = $baseline->projectRoot;
        $sourceDirs = $baseline->getSourceDirs();
        $paths = $this->pathResolver->resolve($root, $sourceDirs);
        $releasedDays = $this->getReleasedDays($baseline);
        $parallel = $baseline->isParallelEnabled() && $this->pcntlAvailable();

        $findingsByRule = $parallel
            ? $this->runSubChecksParallel($root, $paths, $rules, $releasedDays, $resolver)
            : $this->runSubChecksSequential($root, $paths, $rules, $releasedDays, $resolver);

        $composerBin = $resolver->resolve('composer') ?? 'composer';
        $sub = new SecuritySubCheck($root, $paths);
        $composerAudit = $sub->runComposerAudit($composerBin);
        $findingsByRule['composer_audit'] = $composerAudit['findings'];

        $allFindings = array_merge(...array_values($findingsByRule));
        $totalFindings = count($allFindings);
        $criticalAdvisoryCount = $composerAudit['critical'];
        $baselineAdvisoryCount = $baseline->get('security', 'advisories', 0);

        $status = Status::Pass;
        $actions = null;

        if ($totalFindings > 0 || $criticalAdvisoryCount > 0) {
            $status = Status::Fail;
            $activeRules = array_filter($findingsByRule, static fn (array $f): bool => $f !== []);
            $ruleSummaries = array_map(
                static fn (string $rule): string => $rule.': '.count($findingsByRule[$rule]).' finding(s)',
                array_keys($activeRules),
            );
            if ($criticalAdvisoryCount > 0) {
                $ruleSummaries[] = sprintf('composer_audit: %d critical/high', $criticalAdvisoryCount);
            }
            $actions = [[
                'type' => ActionType::FixSecurity,
                'message' => implode('; ', $ruleSummaries),
                'files' => array_slice($allFindings, 0, 50),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'security',
            label: 'Security Audit',
            message: sprintf('%d finding(s), %d critical/high advisories', $totalFindings, $criticalAdvisoryCount),
            severity: Severity::Block,
            baseline: ['advisories' => $baselineAdvisoryCount],
            current: ['findings' => $totalFindings, 'critical' => $criticalAdvisoryCount],
            actions: $actions,
            details: $findingsByRule,
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function runSubChecksSequential(string $root, array $paths, array $rules, int $releasedDays, ToolResolver $resolver): array
    {
        $sub = new SecuritySubCheck($root, $paths);
        $findingsByRule = [];

        foreach (self::SUB_CHECK_METHODS as $rule => $method) {
            if (! ($rules[$rule] ?? true)) {
                $findingsByRule[$rule] = [];

                continue;
            }

            $findingsByRule[$rule] = $rule === 'package_freshness'
                ? $sub->checkPackageFreshness($releasedDays)
                : $sub->{$method}();
        }

        return $findingsByRule;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function runSubChecksParallel(string $root, array $paths, array $rules, int $releasedDays, ToolResolver $resolver): array
    {
        $tempFiles = [];
        $pids = [];
        $ruleOrder = [];

        foreach (self::SUB_CHECK_METHODS as $rule => $method) {
            if (! ($rules[$rule] ?? true)) {
                continue;
            }

            $ruleOrder[] = $rule;
            $isPackageFreshness = $rule === 'package_freshness';
            $args = $isPackageFreshness ? [$releasedDays] : [];
            $tempFiles[$rule] = tempnam(sys_get_temp_dir(), 'catraca_sec_'.posix_getpid().'_');

            $pid = pcntl_fork();

            if ($pid === -1) {
                $tempFiles[$rule] = null;

                continue;
            }

            if ($pid === 0) {
                $sub = new SecuritySubCheck($root, $paths);
                $result = $sub->{$method}(...$args);
                file_put_contents($tempFiles[$rule], json_encode($result, JSON_UNESCAPED_SLASHES));
                exit(0);
            }

            $pids[$rule] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $findingsByRule = [];

        foreach ($ruleOrder as $rule) {
            $tempPath = $tempFiles[$rule] ?? null;

            if ($tempPath === null || ! file_exists($tempPath)) {
                $findingsByRule[$rule] = [];

                continue;
            }

            $raw = file_get_contents($tempPath);
            @unlink($tempPath);

            if ($raw === false || $raw === '') {
                $findingsByRule[$rule] = [];

                continue;
            }

            $data = json_decode($raw, true);
            $findingsByRule[$rule] = is_array($data) ? $data : [];
        }

        foreach (array_keys(self::SUB_CHECK_METHODS) as $rule) {
            if (! isset($findingsByRule[$rule])) {
                $findingsByRule[$rule] = [];
            }
        }

        return $findingsByRule;
    }

    /**
     * @return array<string, bool>
     */
    private function getEnabledRules(Baseline $baseline): array
    {
        $rules = $baseline->get('security', 'rules', null);
        if (is_array($rules) && $rules !== []) {
            return $rules;
        }

        return self::DEFAULT_RULES;
    }

    private function getReleasedDays(Baseline $baseline): int
    {
        $days = $baseline->get('security', 'released_days', null);
        if (is_int($days) && $days > 0) {
            return $days;
        }

        return 3;
    }

    private function pcntlAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_getpid');
    }
}
