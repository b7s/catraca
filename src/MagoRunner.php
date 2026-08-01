<?php

declare(strict_types=1);

namespace B7S\Catraca;

use RuntimeException;
use Symfony\Component\Process\Process;

use function array_merge;
use function sprintf;
use function trim;

final class MagoRunner
{
    /** @param array<int, string> $paths */
    public function diagnostics(string $mago, string $command, array $paths, Baseline $baseline): MagoRunResult
    {
        $arguments = array_merge($this->baseArguments($mago, $baseline), [$command], $paths, [
            '--reporting-format',
            'json',
            '--minimum-report-level',
            $baseline->getMagoMinimumReportLevel(),
            '--minimum-fail-level',
            $baseline->getMagoMinimumReportLevel(),
        ]);

        $result = $this->execute($arguments, $baseline, $command);
        $issues = MagoResultParser::parse($result->output);
        if ($issues === null) {
            throw new RuntimeException(sprintf('Mago %s returned an invalid JSON report.', $command));
        }

        return new MagoRunResult($result->exitCode, $result->output, $result->errorOutput, $issues);
    }

    /** @param array<int, string> $paths */
    public function format(string $mago, array $paths, Baseline $baseline, bool $check): MagoRunResult
    {
        $arguments = array_merge($this->baseArguments($mago, $baseline), ['format']);
        if ($check) {
            $arguments[] = '--check';
        }
        $arguments = array_merge($arguments, $paths);

        return $this->execute($arguments, $baseline, 'format');
    }

    /** @param array<int, string> $paths */
    public function fixLint(string $mago, array $paths, Baseline $baseline): MagoRunResult
    {
        return $this->execute(
            array_merge(
                $this->baseArguments($mago, $baseline),
                ['lint'],
                $paths,
                ['--fix', '--format-after-fix', '--fail-on-remaining'],
            ),
            $baseline,
            'lint',
        );
    }

    /** @return array<int, string> */
    private function baseArguments(string $mago, Baseline $baseline): array
    {
        return [
            $mago,
            '--workspace',
            $baseline->projectRoot,
            '--threads',
            (string) $baseline->getMagoThreads(),
            '--colors',
            'never',
        ];
    }

    /** @param array<int, string> $arguments */
    private function execute(array $arguments, Baseline $baseline, string $command): MagoRunResult
    {
        $process = new Process(
            $arguments,
            $baseline->projectRoot,
            timeout: $baseline->getGateTimeout($this->gateFor($command)),
        );
        $process->run();

        $exitCode = $process->getExitCode() ?? 2;
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        if ($exitCode >= 2) {
            $error = trim($errorOutput !== '' ? $errorOutput : $output);
            throw new RuntimeException(sprintf('Mago %s failed (exit %d): %s', $command, $exitCode, $error));
        }

        return new MagoRunResult($exitCode, $output, $errorOutput);
    }

    private function gateFor(string $command): string
    {
        return match ($command) {
            'format' => 'style',
            'analyze' => 'static_analysis',
            default => 'performance',
        };
    }
}
