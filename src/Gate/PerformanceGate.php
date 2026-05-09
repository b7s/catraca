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

        $importResult = $this->checkFunctionImports($resolver);
        if ($importResult !== null) {
            $violations += $importResult['violations'];
            $files = array_merge($files, $importResult['files']);
            $messages[] = $importResult['message'];
        }

        if ($violations === 0 && $messages === []) {
            return new GateResult(
                status: Status::Skip,
                name: 'performance',
                label: 'Performance',
                message: 'No performance tools found',
                severity: Severity::Warn,
            );
        }

        $baselineViolations = $baseline->get('performance', 'violations', 0);
        [$status, $actions] = $this->evaluateViolations($violations, $files);

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

    private function checkFunctionImports(ToolResolver $resolver): ?array
    {
        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer === null) {
            return null;
        }

        $process = new Process([
            $resolver->resolvePhp(), $fixer,
            'fix',
            '--dry-run',
            '--diff',
            '--format=json',
            '--rules=global_namespace_import',
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

        return [
            'violations' => $violations,
            'files' => $files,
            'message' => $violations > 0
                ? sprintf('%d functions should use "use function"', $violations)
                : 'Function imports OK',
        ];
    }

    private function evaluateViolations(int $violations, array $files): array
    {
        $status = Status::Pass;
        $actions = null;

        if ($violations > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::ImprovePerformance,
                'message' => sprintf('Fix %d performance improvement(s)', $violations),
                'files' => array_slice($files, 0, 50),
            ]];
        }

        return [$status, $actions];
    }
}
