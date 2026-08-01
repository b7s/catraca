<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
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

use function array_slice;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

readonly class StaticAnalysisGate implements GateInterface
{
    public function __construct(
        private SourcePathResolver $pathResolver = new SourcePathResolver(),
        private MagoRunner $magoRunner = new MagoRunner(),
    ) {}

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $tool = GateToolRegistry::resolve($baseline, $resolver, 'static_analysis');
        if ($tool !== null) {
            return match ($tool->name) {
                'mago' => $this->runMago($tool->path, $baseline),
                'phpstan' => $this->runPhpstan($tool->path, $baseline, $resolver),
                default => $this->runPsalm($tool->path, $baseline, $resolver),
            };
        }

        return new GateResult(
            status: Status::Skip,
            name: 'static_analysis',
            label: 'Static Analysis',
            message: 'No static analysis tool found (install mago, phpstan, or psalm)',
            severity: Severity::Warn,
        );
    }

    private function runMago(string $mago, Baseline $baseline): GateResult
    {
        $result = $this->magoRunner->diagnostics(
            $mago,
            'analyze',
            $this->pathResolver->resolveForBaseline($baseline),
            $baseline,
        );

        return $this->buildResult($result->issueCount(), $result->issues, $result->files(), $baseline, 'Mago');
    }

    private function runPhpstan(string $phpstan, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $args = [
            $resolver->resolvePhp(),
            $phpstan,
            'analyse',
            '--memory-limit=512M',
            '--error-format=json',
            '--no-progress',
        ];

        if (!$this->hasPhpstanConfig($baseline->projectRoot)) {
            $args[] = '--level=5';
        }

        $process = new Process($args, timeout: $baseline->getGateTimeout('static_analysis'));
        $process->setWorkingDirectory($baseline->projectRoot);
        $process->run();

        $output = $process->getOutput() !== '' ? $process->getOutput() : $process->getErrorOutput();
        /** @var mixed $data */
        $data = json_decode($output, true);

        /** @var array<int, array{file: string, line: int, message: string, ignorable: bool}> $errors */
        $errors = [];
        /** @var array<int, string> $files */
        $files = [];
        /** @var array{file_errors: int, errors: int} $totals */
        $totals = ['file_errors' => 0, 'errors' => 0];

        if (is_array($data)) {
            /** @var array<string, mixed> $rawTotals */
            $rawTotals = $data['totals'] ?? [];
            $totals = [
                'file_errors' => is_int($rawTotals['file_errors'] ?? null) ? $rawTotals['file_errors'] : 0,
                'errors' => is_int($rawTotals['errors'] ?? null) ? $rawTotals['errors'] : 0,
            ];

            /** @var array<string, mixed> $rawFiles */
            $rawFiles = $data['files'] ?? [];
            foreach ($rawFiles as $filePath => $fileData) {
                if (!is_string($filePath) || !is_array($fileData)) {
                    continue;
                }
                /** @var array<int, mixed> $messages */
                $messages = $fileData['messages'] ?? [];
                foreach ($messages as $msg) {
                    if (!is_array($msg)) {
                        continue;
                    }
                    $errors[] = [
                        'file' => $filePath,
                        'line' => is_int($msg['line'] ?? null) ? $msg['line'] : 0,
                        'message' => is_string($msg['message'] ?? null) ? $msg['message'] : '',
                        'ignorable' => ($msg['ignorable'] ?? true) === true,
                    ];
                    $files[] = $filePath . ':' . (is_int($msg['line'] ?? null) ? $msg['line'] : 0);
                }
            }
        }

        $errorCount = $totals['file_errors'] + $totals['errors'];

        return $this->buildResult($errorCount, $errors, $files, $baseline, 'PHPStan');
    }

    private function runPsalm(string $psalm, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $process = new Process([
            $resolver->resolvePhp(),
            $psalm,
            '--output-format=json',
            '--no-progress',
        ], timeout: $baseline->getGateTimeout('static_analysis'));
        $process->run();

        $output = $process->getOutput() !== '' ? $process->getOutput() : $process->getErrorOutput();
        /** @var mixed $data */
        $data = json_decode($output, true);

        /** @var array<int, array{file: string, line: int, message: string, severity: string}> $errors */
        $errors = [];
        /** @var array<int, string> $files */
        $files = [];

        if (is_array($data)) {
            foreach ($data as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $filePath = $issue['file_path'] ?? null;
                if (is_string($filePath)) {
                    $line = is_int($issue['line_from'] ?? null) ? $issue['line_from'] : 0;
                    $errors[] = [
                        'file' => $filePath,
                        'line' => $line,
                        'message' => is_string($issue['message'] ?? null) ? $issue['message'] : '',
                        'severity' => is_string($issue['severity'] ?? null) ? $issue['severity'] : 'error',
                    ];
                    $files[] = $filePath . ':' . $line;
                }
            }
        }

        return $this->buildResult(count($errors), $errors, $files, $baseline, 'Psalm');
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<int, string>  $files
     */
    private function buildResult(
        int $errorCount,
        array $errors,
        array $files,
        Baseline $baseline,
        string $toolName,
    ): GateResult {
        $baselineErrors = $baseline->getIntResult('static_analysis', 'errors', 0);

        $status = Status::Pass;
        $actions = null;

        if ($errorCount > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::FixSA,
                'message' => sprintf('Fix %d %s errors', $errorCount, $toolName),
                'files' => array_slice($files, 0, 50),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'static_analysis',
            label: 'Static Analysis',
            message: sprintf('%d errors (baseline: %d) via %s', $errorCount, $baselineErrors, $toolName),
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
