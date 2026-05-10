<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;
use RuntimeException;
use Symfony\Component\Process\Process;

use function sprintf;

class CoverageGate implements GateInterface
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $phpunit = $resolver->resolve('phpunit');
        if ($phpunit !== null) {
            return $this->runPhpunit($phpunit, $baseline, $resolver);
        }

        $pest = $resolver->resolve('pest');
        if ($pest !== null) {
            return $this->runPest($pest, $baseline, $resolver);
        }

        return new GateResult(
            status: Status::Skip,
            name: 'coverage',
            label: 'Test Coverage',
            message: 'No test runner found (install phpunit or pest)',
            severity: Severity::Warn,
        );
    }

    private function runPhpunit(string $phpunit, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $tmpDir = sys_get_temp_dir().'/catraca-'.uniqid('', true);
        if (! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
        }

        $cloverPath = $tmpDir.'/clover.xml';

        $process = new Process([
            $resolver->resolvePhp(), $phpunit,
            '--coverage-clover='.$cloverPath,
            '--coverage-text',
        ]);
        $process->run();

        $coverage = $this->parseClover($cloverPath);
        if ($coverage === null) {
            $coverage = $this->parseCoverageFromText($process->getOutput());
        }

        $this->cleanup($tmpDir);

        $baselineCoverage = $this->getBaselineCoverage($baseline);
        [$status, $actions] = $this->evaluateCoverage($coverage, $baselineCoverage);

        $message = $coverage !== null
            ? sprintf('%.2f%% (baseline: %.2f%%)', $coverage, $baselineCoverage)
            : 'Could not determine coverage (is xdebug or pcov enabled?)';

        return new GateResult(
            status: $status,
            name: 'coverage',
            label: 'Test Coverage',
            message: $message,
            severity: Severity::Block,
            baseline: ['percentage' => $baselineCoverage],
            current: ['percentage' => $coverage],
            actions: $actions,
        );
    }

    private function runPest(string $pest, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $process = new Process([
            $resolver->resolvePhp(), $pest,
            '--coverage',
        ]);
        $process->run();

        $coverage = $this->parseCoverageFromText($process->getOutput());
        $baselineCoverage = $this->getBaselineCoverage($baseline);
        [$status, $actions] = $this->evaluateCoverage($coverage, $baselineCoverage);

        $message = $coverage !== null
            ? sprintf('%.2f%% (baseline: %.2f%%)', $coverage, $baselineCoverage)
            : 'Could not determine coverage';

        return new GateResult(
            status: $status,
            name: 'coverage',
            label: 'Test Coverage',
            message: $message,
            severity: Severity::Block,
            baseline: ['percentage' => $baselineCoverage],
            current: ['percentage' => $coverage],
            actions: $actions,
        );
    }

    private function getBaselineCoverage(Baseline $baseline): float
    {
        $val = $baseline->get('coverage', 'percentage', 85.0);

        return is_numeric($val) ? (float) $val : 85.0;
    }

    private function evaluateCoverage(?float $coverage, float $baselineCoverage): array
    {
        $status = Status::Pass;
        $actions = null;

        if ($coverage !== null && $coverage < $baselineCoverage) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::AddTests,
                'message' => sprintf('Coverage dropped from %.2f%% to %.2f%% — add more tests', $baselineCoverage, $coverage),
                'files' => [],
            ]];
        }

        return [$status, $actions];
    }

    private function parseClover(string $path): ?float
    {
        if (! file_exists($path)) {
            return null;
        }

        $xml = @simplexml_load_file($path);
        if ($xml === false) {
            return null;
        }

        $metrics = $xml->project->metrics ?? $xml->metrics;
        if ($metrics === null) {
            return null;
        }

        $statements = (float) ($metrics['statements'] ?? 0);
        $covered = (float) ($metrics['coveredstatements'] ?? 0);

        if ($statements <= 0) {
            return null;
        }

        return round(($covered / $statements) * 100, 2);
    }

    private function parseCoverageFromText(string $output): ?float
    {
        if (preg_match('/lines.*?(\d+\.?\d*)%/', $output, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/(\d+\.?\d*)\s*%\s*(covered|coverage)/i', $output, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = glob($dir.'/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
