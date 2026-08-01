<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use B7S\Catraca\Analyzer\ConditionOrderAnalyzer;
use B7S\Catraca\Baseline;
use B7S\Catraca\CsFixerResultParser;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\MagoRunner;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use Symfony\Component\Process\Process;

use function array_merge;
use function array_slice;
use function count;
use function is_array;
use function is_int;
use function sprintf;

readonly class PerformanceGate implements GateInterface
{
    private const int MAX_VIOLATIONS = 0;

    public function __construct(
        private SourcePathResolver $pathResolver = new SourcePathResolver(),
        private MagoRunner $magoRunner = new MagoRunner(),
    ) {}

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $violations = 0;
        $files = [];
        $messages = [];
        $reasons = [];
        $hasTool = false;

        $enabledRules = $this->getEnabledRules($baseline);
        $tool = GateToolRegistry::resolve($baseline, $resolver, 'performance');
        $mago = $tool?->name === 'mago' ? $tool->path : null;
        $fixer = $tool?->name === 'php-cs-fixer' ? $tool->path : null;
        $paths = $this->pathResolver->resolveForBaseline($baseline);

        if ($mago !== null) {
            $result = $this->magoRunner->diagnostics($mago, 'lint', $paths, $baseline);
            $hasTool = true;
            $violations = $result->issueCount();
            $files = $result->files();

            foreach ($result->issues as $issue) {
                $prefix = $issue['code'] === '' ? '' : $issue['code'] . ': ';
                $reasons[] = $prefix . $issue['message'];
            }

            if ($violations > self::MAX_VIOLATIONS) {
                $messages[] = sprintf('%d Mago lint improvement(s) available', $violations);
            }
        } elseif ($fixer !== null) {
            $rulesJson = self::buildRulesJson($enabledRules);

            if ($rulesJson !== '{}') {
                $result = $this->runCsFixerRules(
                    $fixer,
                    $resolver,
                    $rulesJson,
                    $paths,
                    $baseline->getGateTimeout('performance'),
                );
                $hasTool = true;
                $violations = $result['violations'];
                $files = $result['files'];

                if ($violations > self::MAX_VIOLATIONS) {
                    $messages[] = sprintf('%d files with performance improvements available', $violations);
                }
            }
        }

        if ($enabledRules['autoload_optimization'] ?? true) {
            $autoloadResult = $this->checkAutoloadOptimization($resolver);
            if ($autoloadResult !== null) {
                $hasTool = true;
                $violations += $autoloadResult['violations'];
                $files = array_merge($files, $autoloadResult['files']);
                if ($autoloadResult['violations'] > self::MAX_VIOLATIONS) {
                    $messages[] = $autoloadResult['message'];
                }
            }
        }

        if ($enabledRules['condition_order'] ?? true) {
            $conditionResult = $this->checkConditionOrder($paths);
            $hasTool = true;
            $violations += $conditionResult['violations'];
            $files = array_merge($files, $conditionResult['files']);
            $reasons = array_merge($reasons, $conditionResult['reasons']);
            if ($conditionResult['violations'] > self::MAX_VIOLATIONS) {
                $messages[] = $conditionResult['message'];
            }
        }

        if (!$hasTool) {
            return new GateResult(
                status: Status::Skip,
                name: 'performance',
                label: 'Performance',
                message: 'No performance tools found',
                severity: Severity::Warn,
            );
        }

        $baselineViolations = $baseline->getResult('performance', 'violations', self::MAX_VIOLATIONS);
        [$status, $actions] = $this->evaluateViolations($violations, $files, $messages, $reasons);

        $message = $violations > self::MAX_VIOLATIONS
            ? sprintf(
                '%d improvement(s) found (baseline: %d)',
                $violations,
                is_int($baselineViolations) ? $baselineViolations : self::MAX_VIOLATIONS,
            )
            : 'No performance improvements needed';

        return new GateResult(
            status: $status,
            name: 'performance',
            label: 'Performance',
            message: $message,
            severity: Severity::Block,
            baseline: ['violations' => $baselineViolations],
            current: ['violations' => $violations],
            actions: $actions,
        );
    }

    /**
     * @return array<string, array{rule: string, message: string}>
     */
    public static function getRuleRegistry(): array
    {
        return [
            'global_namespace_import' => [
                'rule' => '{"global_namespace_import":{"import_classes":true,"import_constants":true,"import_functions":true}}',
                'message' => '%d global imports missing (use class/function/const)',
            ],
            'no_unused_imports' => [
                'rule' => 'no_unused_imports',
                'message' => '%d files with unused imports',
            ],
            'fully_qualified_strict_types' => [
                'rule' => '{"fully_qualified_strict_types":{"import_symbols":true}}',
                'message' => '%d files with redundant FQCNs',
            ],
            'lambda_not_used_import' => [
                'rule' => 'lambda_not_used_import',
                'message' => '%d closures with unused "use" variables',
            ],
            'native_function_invocation' => [
                'rule' => '{"native_function_invocation":{"include":["@compiler_optimized"],"scope":"all","strict":true}}',
                'message' => '%d native function calls without backslash prefix',
            ],
            'no_redundant_readonly_property' => [
                'rule' => 'no_redundant_readonly_property',
                'message' => '%d redundant readonly property declarations',
            ],
            'static_lambda' => [
                'rule' => 'static_lambda',
                'message' => '%d lambdas that should be declared static',
            ],
            'array_push' => [
                'rule' => 'array_push',
                'message' => '%d array_push() calls — use $arr[] = instead',
            ],
            'ereg_to_preg' => [
                'rule' => 'ereg_to_preg',
                'message' => '%d deprecated ereg function calls',
            ],
            'modernize_strpos' => [
                'rule' => 'modernize_strpos',
                'message' => '%d strpos() calls — use str_contains/str_starts_with/str_ends_with',
            ],
            'pow_to_exponentiation' => [
                'rule' => 'pow_to_exponentiation',
                'message' => '%d pow() calls — use ** operator instead',
            ],
            'random_api_migration' => [
                'rule' => 'random_api_migration',
                'message' => '%d rand()/mt_rand() calls — use random_int() instead',
            ],
            'set_type_to_cast' => [
                'rule' => 'set_type_to_cast',
                'message' => '%d settype() calls — use type casting instead',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $enabledRules
     */
    public static function buildRulesJson(array $enabledRules): string
    {
        $registry = self::getRuleRegistry();
        $rules = [];
        foreach ($registry as $key => $config) {
            if (!($enabledRules[$key] ?? false)) {
                continue;
            }
            $decoded = json_decode($config['rule'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $name => $cfg) {
                    $rules[$name] = $cfg;
                }
            } else {
                $rules[$config['rule']] = true;
            }
        }

        return $rules === [] ? '{}' : (json_encode($rules, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /**
     * @return array<string, bool>
     */
    private function getEnabledRules(Baseline $baseline): array
    {
        $rules = $baseline->getConfig('performance', 'rules', []);
        if (is_array($rules) && $rules !== []) {
            return $rules;
        }

        $defaults = array_fill_keys(array_keys(self::getRuleRegistry()), true);
        $defaults['autoload_optimization'] = true;

        return $defaults;
    }

    private function runCsFixerRules(
        string $fixer,
        ToolResolver $resolver,
        string $rulesJson,
        array $paths,
        ?float $timeout,
    ): array {
        $cmd = [
            $resolver->resolvePhp(),
            $fixer,
            'fix',
            '--dry-run',
            '--diff',
            '--allow-risky=yes',
            '--using-cache=no',
            '--format=json',
            '--rules=' . $rulesJson,
        ];
        foreach ($paths as $path) {
            $cmd[] = $path;
        }

        $process = new Process($cmd, timeout: $timeout);
        $process->run();

        return CsFixerResultParser::parseJsonOutput($process->getOutput());
    }

    /**
     * @return array{violations: int, files: array<int, string>, message: string}|null
     */
    private function checkAutoloadOptimization(ToolResolver $resolver): ?array
    {
        $composer = $resolver->resolve('composer');
        if ($composer === null) {
            return null;
        }

        $autoloadFile = $resolver->getProjectRoot() . '/vendor/composer/autoload_classmap.php';

        if (!file_exists($autoloadFile)) {
            return [
                'violations' => 1,
                'files' => [],
                'message' => 'Autoload not optimized — run "composer dump-autoload -o"',
            ];
        }

        return [
            'violations' => 0,
            'files' => [],
            'message' => '',
        ];
    }

    /**
     * @param  array<int, string>  $paths
     * @return array{violations: int, files: array<int, string>, reasons: array<int, string>, message: string}
     */
    private function checkConditionOrder(array $paths): array
    {
        $analyzer = new ConditionOrderAnalyzer();
        $violations = $analyzer->analyze($paths);

        if ($violations === []) {
            return [
                'violations' => 0,
                'files' => [],
                'reasons' => [],
                'message' => '',
            ];
        }

        $files = [];
        $reasons = [];
        foreach ($violations as $v) {
            $files[] = $v['file'] . ':' . $v['line'];
            $reasons[] = $v['message'];
        }

        return [
            'violations' => count($violations),
            'files' => $files,
            'reasons' => $reasons,
            'message' => sprintf(
                '%d condition order issues — cheaper conditions should come first',
                count($violations),
            ),
        ];
    }

    /**
     * @param  array<int, string>  $files
     * @param  array<int, string>  $messages
     * @param  array<int, string>  $reasons
     * @return array{0: Status, 1: array<array{type: ActionType, message: string, files: array<int, string>, reasons: array<int, string>}>|null}
     */
    private function evaluateViolations(int $violations, array $files, array $messages, array $reasons = []): array
    {
        $status = Status::Pass;
        $actions = null;

        if ($violations > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::ImprovePerformance,
                'message' => implode('; ', $messages),
                'files' => array_slice($files, 0, 50),
                'reasons' => array_slice($reasons, 0, 50),
            ]];
        }

        return [$status, $actions];
    }
}
