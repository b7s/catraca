<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
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

        $importResult = $this->checkGlobalImports($resolver);
        if ($importResult !== null) {
            $hasTool = true;
            $violations += $importResult['violations'];
            $files = array_merge($files, $importResult['files']);
            if ($importResult['violations'] > 0) {
                $messages[] = $importResult['message'];
            }
        }

        $unusedResult = $this->checkUnusedImports($resolver);
        if ($unusedResult !== null) {
            $hasTool = true;
            $violations += $unusedResult['violations'];
            $files = array_merge($files, $unusedResult['files']);
            if ($unusedResult['violations'] > 0) {
                $messages[] = $unusedResult['message'];
            }
        }

        $autoloadResult = $this->checkAutoloadOptimization($resolver);
        if ($autoloadResult !== null) {
            $hasTool = true;
            $violations += $autoloadResult['violations'];
            $files = array_merge($files, $autoloadResult['files']);
            if ($autoloadResult['violations'] > 0) {
                $messages[] = $autoloadResult['message'];
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

    private function checkGlobalImports(ToolResolver $resolver): ?array
    {
        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer === null) {
            return null;
        }

        $result = $this->runCsFixerRule($fixer, $resolver, '{"global_namespace_import":{"import_classes":true,"import_constants":true,"import_functions":true}}');

        return [
            'violations' => $result['violations'],
            'files' => $result['files'],
            'message' => sprintf('%d global imports missing (use class/function/const)', $result['violations']),
        ];
    }

    private function checkUnusedImports(ToolResolver $resolver): ?array
    {
        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer === null) {
            return null;
        }

        $result = $this->runCsFixerRule($fixer, $resolver, 'no_unused_imports');

        return [
            'violations' => $result['violations'],
            'files' => $result['files'],
            'message' => sprintf('%d files with unused imports', $result['violations']),
        ];
    }

    private function runCsFixerRule(string $fixer, ToolResolver $resolver, string $rules): array
    {
        $process = new Process([
            $resolver->resolvePhp(), $fixer,
            'fix',
            '--dry-run',
            '--diff',
            '--format=json',
            '--rules='.$rules,
        ]);
        $process->run();

        $output = $process->getOutput();
        $violations = 0;
        /** @var array<int, string> $files */
        $files = [];

        $data = json_decode($output, true);
        if (is_array($data)) {
            foreach ($data as $value) {
                if (is_array($value) && isset($value['file']) && is_string($value['file'])) {
                    $files[] = $value['file'];
                }
            }
            $violations = count($files);
        }

        return ['violations' => $violations, 'files' => $files];
    }

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
