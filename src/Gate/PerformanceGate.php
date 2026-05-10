<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\CsFixerResultParser;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;
use Symfony\Component\Process\Process;

class PerformanceGate implements GateInterface
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $violations = 0;
        $files = [];
        $messages = [];
        $hasTool = false;

        $enabledRules = $this->getEnabledRules($baseline);
        $fixer = $resolver->resolve('php-cs-fixer');

        if ($fixer !== null) {
            foreach ($this->getRuleRegistry() as $key => $config) {
                if (! ($enabledRules[$key] ?? false)) {
                    continue;
                }

                $result = $this->runCsFixerRule($fixer, $resolver, $config['rule']);
                $hasTool = true;
                $violations += $result['violations'];

                array_push($files, ...$result['files']);

                if ($result['violations'] > 0) {
                    $messages[] = sprintf($config['message'], $result['violations']);
                }
            }
        }

        if ($enabledRules['autoload_optimization'] ?? true) {
            $autoloadResult = $this->checkAutoloadOptimization($resolver);
            if ($autoloadResult !== null) {
                $hasTool = true;
                $violations += $autoloadResult['violations'];
                $files = array_merge($files, $autoloadResult['files']);
                if ($autoloadResult['violations'] > 0) {
                    $messages[] = $autoloadResult['message'];
                }
            }
        }

        if (! $hasTool) {
            return new GateResult(
                status: Status::Skip,
                name: 'performance',
                label: 'Performance',
                message: 'No performance tools found',
                severity: Severity::Warn,
            );
        }

        $baselineViolations = $baseline->get('performance', 'violations', 0);
        [$status, $actions] = $this->evaluateViolations($violations, $files, $messages);

        $message = $violations > 0
            ? sprintf('%d improvement(s) found (baseline: %d)', $violations, is_int($baselineViolations) ? $baselineViolations : 0)
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
    private function getRuleRegistry(): array
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
     * @return array<string, bool>
     */
    private function getEnabledRules(Baseline $baseline): array
    {
        $rules = $baseline->get('performance', 'rules', []);
        if (is_array($rules) && $rules !== []) {
            return $rules;
        }

        $defaults = array_fill_keys(array_keys($this->getRuleRegistry()), true);
        $defaults['autoload_optimization'] = true;

        return $defaults;
    }

    private function runCsFixerRule(string $fixer, ToolResolver $resolver, string $rules): array
    {
        $process = new Process([
            $resolver->resolvePhp(), $fixer,
            'fix',
            '--dry-run',
            '--diff',
            '--allow-risky=yes',
            '--format=json',
            '--rules='.$rules,
        ]);
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

        $autoloadFile = $resolver->getProjectRoot().'/vendor/composer/autoload_classmap.php';

        if (! file_exists($autoloadFile)) {
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

    private function evaluateViolations(int $violations, array $files, array $messages): array
    {
        $status = Status::Pass;
        $actions = null;

        if ($violations > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::ImprovePerformance,
                'message' => implode('; ', $messages),
                'files' => array_slice($files, 0, 50),
            ]];
        }

        return [$status, $actions];
    }
}
