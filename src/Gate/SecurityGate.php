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
use Parallite\ForkExecutor;
use Parallite\ParalliteClient;
use Throwable;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;

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
        'laravel_owasp' => true,
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
        'laravel_owasp' => 'checkLaravelOwasp',
    ];

    public function __construct(
        private SourcePathResolver $pathResolver = new SourcePathResolver(),
    ) {}

    /**
     * @throws Throwable
     */
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $rules = $this->getEnabledRules($baseline);
        $root = $baseline->projectRoot;
        $paths = $this->pathResolver->resolveForBaseline($baseline);
        $releasedDays = $this->getReleasedDays($baseline);
        $parallel = $baseline->isParallelEnabled() && ForkExecutor::isAvailable();

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
        $baselineAdvisoryCount = $baseline->getResult('security', 'advisories', 0);

        $baselineFindings = $baseline->getResult('security', 'findings', 0);
        $baselineFindings = is_int($baselineFindings) ? $baselineFindings : 0;
        $status = Status::Pass;
        $actions = null;

        if ($totalFindings > 0 || $criticalAdvisoryCount > 0) {
            $status = Status::Fail;
            $activeRules = array_filter($findingsByRule, static fn(array $f): bool => $f !== []);
            $ruleSummaries = array_map(
                static fn(string $rule): string => $rule . ': ' . count($findingsByRule[$rule]) . ' finding(s)',
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
            baseline: ['advisories' => $baselineAdvisoryCount, 'findings' => $baselineFindings],
            current: ['findings' => $totalFindings, 'critical' => $criticalAdvisoryCount],
            actions: $actions,
            details: $findingsByRule,
        );
    }

    /**
     * @return array<string, array<int, string>>
     * @param  array<int, string>  $paths
     * @param  array<string, bool>  $rules
     */
    private function runSubChecksSequential(
        string $root,
        array $paths,
        array $rules,
        int $releasedDays,
        ToolResolver $resolver,
    ): array {
        $sub = new SecuritySubCheck($root, $paths);
        $findingsByRule = [];

        foreach (self::SUB_CHECK_METHODS as $rule => $method) {
            if (!($rules[$rule] ?? true)) {
                $findingsByRule[$rule] = [];

                continue;
            }

            $findingsByRule[$rule] = self::runSubCheck($sub, $rule, $releasedDays);
        }

        return $findingsByRule;
    }

    /**
     * @return array<string, array<int, string>>
     * @param  array<int, string>  $paths
     * @param  array<string, bool>  $rules
     *
     * @throws Throwable
     */
    private function runSubChecksParallel(
        string $root,
        array $paths,
        array $rules,
        int $releasedDays,
        ToolResolver $resolver,
    ): array {
        $client = new ParalliteClient();
        $closures = [];
        $ruleOrder = [];

        foreach (self::SUB_CHECK_METHODS as $rule => $_method) {
            if (!($rules[$rule] ?? true)) {
                continue;
            }

            $ruleOrder[] = $rule;
            $closures[] = static fn(): array => self::runSubCheck(
                new SecuritySubCheck($root, $paths),
                $rule,
                $releasedDays,
            );
        }

        $rawResults = $client->awaitAll($closures);

        $findingsByRule = [];
        foreach ($ruleOrder as $i => $rule) {
            $data = $rawResults[$i] ?? null;
            $findingsByRule[$rule] = self::stringList($data);
        }

        foreach (array_keys(self::SUB_CHECK_METHODS) as $rule) {
            if (!isset($findingsByRule[$rule])) {
                $findingsByRule[$rule] = [];
            }
        }

        return $findingsByRule;
    }

    /** @return array<int, string> */
    private static function runSubCheck(SecuritySubCheck $subCheck, string $rule, int $releasedDays): array
    {
        return self::stringList(match ($rule) {
            'hardcoded_secrets' => $subCheck->checkHardcodedSecrets(),
            'sql_injection' => $subCheck->checkSqlInjection(),
            'command_injection' => $subCheck->checkCommandInjection(),
            'csrf_protection' => $subCheck->checkCsrf(),
            'path_traversal' => $subCheck->checkPathTraversal(),
            'insecure_deserialization' => $subCheck->checkInsecureDeserialization(),
            'ssrf' => $subCheck->checkSsrf(),
            'tls_verification' => $subCheck->checkTlsVerification(),
            'insecure_rng' => $subCheck->checkInsecureRng(),
            'gitignore_sensitive' => $subCheck->checkGitignore(),
            'package_freshness' => $subCheck->checkPackageFreshness($releasedDays),
            'weak_cryptography' => $subCheck->checkWeakCryptography(),
            'cors_config' => $subCheck->checkCorsConfig(),
            'npm_audit' => $subCheck->checkNpmAudit(),
            'laravel_owasp' => $subCheck->checkLaravelOwasp(),
            default => [],
        });
    }

    /** @return array<int, string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, bool>
     */
    private function getEnabledRules(Baseline $baseline): array
    {
        $rules = $baseline->getConfig('security', 'rules', null);
        if (is_array($rules) && $rules !== []) {
            $enabled = self::DEFAULT_RULES;
            foreach ($rules as $rule => $value) {
                if (is_string($rule) && is_bool($value)) {
                    $enabled[$rule] = $value;
                }
            }

            return $enabled;
        }

        return self::DEFAULT_RULES;
    }

    private function getReleasedDays(Baseline $baseline): int
    {
        $days = $baseline->getConfig('security', 'released_days', null);
        if (is_int($days) && $days > 0) {
            return $days;
        }

        return 3;
    }
}
