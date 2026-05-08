<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;
use Symfony\Component\Process\Process;

class StaticAnalysisGate
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $phpstan = $resolver->resolve('phpstan');
        if ($phpstan !== null) {
            return $this->runPhpstan($phpstan, $baseline, $resolver);
        }

        $psalm = $resolver->resolve('psalm');
        if ($psalm !== null) {
            return $this->runPsalm($psalm, $baseline, $resolver);
        }

        return new GateResult(
            status: Status::Skip,
            name: 'static_analysis',
            label: 'Static Analysis',
            message: 'No static analysis tool found (install phpstan or psalm)',
            severity: Severity::Warn,
        );
    }

    private function runPhpstan(string $phpstan, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $projectRoot = dirname($baseline->getPath());

        $args = [
            $resolver->resolvePhp(), $phpstan,
            'analyse', '--memory-limit=512M',
            '--error-format=json', '--no-progress',
        ];

        if (!$this->hasPhpstanConfig($projectRoot)) {
            $args[] = '--level=5';
        }

        $process = new Process($args);
        $process->setWorkingDirectory($projectRoot);
        $process->run();

        $output = $process->getOutput() ?: $process->getErrorOutput();
        $data = json_decode($output, true);

        $errors = [];
        $files = [];
        $totals = ['file_errors' => 0, 'errors' => 0];

        if (is_array($data)) {
            $totals = $data['totals'] ?? ['file_errors' => 0, 'errors' => 0];
            foreach ($data['files'] ?? [] as $filePath => $fileData) {
                foreach ($fileData['messages'] ?? [] as $msg) {
                    $errors[] = [
                        'file' => $filePath,
                        'line' => $msg['line'] ?? 0,
                        'message' => $msg['message'] ?? '',
                        'ignorable' => $msg['ignorable'] ?? true,
                    ];
                    $files[] = $filePath . ':' . ($msg['line'] ?? 0);
                }
            }
        }

        $errorCount = ($totals['file_errors'] ?? 0) + ($totals['errors'] ?? 0);
        $baselineErrors = $baseline->get('static_analysis', 'errors', 0);

        $status = Status::Pass;
        $actions = null;

        if ($errorCount > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::FixSA,
                'message' => sprintf('Fix %d PHPStan errors', $errorCount),
                'files' => array_slice($files, 0, 50),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'static_analysis',
            label: 'Static Analysis',
            message: sprintf('%d errors (baseline: %d)', $errorCount, $baselineErrors),
            severity: Severity::Block,
            baseline: ['errors' => $baselineErrors],
            current: ['errors' => $errorCount],
            actions: $actions,
            details: $errorCount > 0 ? ['errors' => array_slice($errors, 0, 100)] : null,
        );
    }

    private function runPsalm(string $psalm, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $process = new Process([
            $resolver->resolvePhp(), $psalm,
            '--output-format=json', '--no-progress',
        ]);
        $process->run();

        $output = $process->getOutput() ?: $process->getErrorOutput();
        $data = json_decode($output, true);

        $errors = [];
        $files = [];

        if (is_array($data)) {
            foreach ($data as $issue) {
                if (isset($issue['file_path'])) {
                    $errors[] = [
                        'file' => $issue['file_path'],
                        'line' => $issue['line_from'] ?? 0,
                        'message' => $issue['message'] ?? '',
                        'severity' => $issue['severity'] ?? 'error',
                    ];
                    $files[] = $issue['file_path'] . ':' . ($issue['line_from'] ?? 0);
                }
            }
        }

        $errorCount = count($errors);
        $baselineErrors = $baseline->get('static_analysis', 'errors', 0);

        $status = Status::Pass;
        $actions = null;

        if ($errorCount > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::FixSA,
                'message' => sprintf('Fix %d Psalm errors', $errorCount),
                'files' => array_slice($files, 0, 50),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'static_analysis',
            label: 'Static Analysis',
            message: sprintf('%d errors (baseline: %d)', $errorCount, $baselineErrors),
            severity: Severity::Block,
            baseline: ['errors' => $baselineErrors],
            current: ['errors' => $errorCount],
            actions: $actions,
            details: $errorCount > 0 ? ['errors' => array_slice($errors, 0, 100)] : null,
        );
    }

    private function hasPhpstanConfig(string $projectRoot): bool
    {
        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $file) {
            if (file_exists($projectRoot . '/' . $file)) {
                return true;
            }
        }
        return false;
    }
}
