<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;
use Symfony\Component\Process\Process;

class DuplicationGate
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $jscpd = $resolver->resolve('jscpd');
        if ($jscpd !== null) {
            return $this->runJscpd($jscpd, $baseline, $resolver);
        }

        $phpcpd = $resolver->resolve('phpcpd');
        if ($phpcpd !== null) {
            return $this->runPhpcpd($phpcpd, $baseline, $resolver);
        }

        $npxJscpd = $resolver->resolve('npx');
        if ($npxJscpd !== null) {
            return $this->runJscpdViaNpx($baseline, $resolver);
        }

        return new GateResult(
            status: Status::Skip,
            name: 'duplication',
            label: 'Duplication',
            message: 'No duplication tool found (install jscpd or phpcpd)',
            severity: Severity::Warn,
        );
    }

    private function runJscpd(string $jscpd, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $tmpDir = sys_get_temp_dir() . '/catraca-' . uniqid();
        @mkdir($tmpDir, 0755, true);

        $projectRoot = dirname($baseline->getPath());

        $process = new Process([
            $jscpd,
            '--threshold', '0',
            '--reporters', 'json',
            '--output', $tmpDir,
            '--ignore', '**/vendor/**,**/node_modules/**',
            $projectRoot . '/src',
            $projectRoot . '/app',
        ]);
        $process->run();

        $reportPath = $tmpDir . '/jscpd-report.json';
        $data = null;
        if (file_exists($reportPath)) {
            $content = file_get_contents($reportPath);
            $data = json_decode($content, true);
        }

        $this->cleanup($tmpDir);

        return $this->parseJscpdResult($data, $baseline);
    }

    private function runJscpdViaNpx(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $tmpDir = sys_get_temp_dir() . '/catraca-' . uniqid();
        @mkdir($tmpDir, 0755, true);

        $projectRoot = dirname($baseline->getPath());

        $process = new Process([
            'npx', 'jscpd',
            '--threshold', '0',
            '--reporters', 'json',
            '--output', $tmpDir,
            '--ignore', '**/vendor/**,**/node_modules/**',
            $projectRoot . '/src',
            $projectRoot . '/app',
        ]);
        $process->run();

        $reportPath = $tmpDir . '/jscpd-report.json';
        $data = null;
        if (file_exists($reportPath)) {
            $content = file_get_contents($reportPath);
            $data = json_decode($content, true);
        }

        $this->cleanup($tmpDir);

        return $this->parseJscpdResult($data, $baseline);
    }

    private function parseJscpdResult(?array $data, Baseline $baseline): GateResult
    {
        $percentage = 0.0;
        $clones = [];
        $cloneDetails = [];

        if ($data !== null) {
            $statistics = $data['statistics'] ?? $data;
            $percentage = (float) ($statistics['total']['percentage'] ?? $statistics['percentage'] ?? 0);

            foreach ($data['duplicates'] ?? $statistics['duplicates'] ?? [] as $dup) {
                $first = $dup['firstFile'] ?? [];
                $second = $dup['secondFile'] ?? [];

                $firstName = $first['name'] ?? $first['path'] ?? 'unknown';
                $secondName = $second['name'] ?? $second['path'] ?? 'unknown';
                $lines = (int) ($dup['lines'] ?? 0);

                $cloneDetails[] = [
                    'file_a' => $firstName . ':' . ($first['start'] ?? 0) . '-' . ($first['end'] ?? 0),
                    'file_b' => $secondName . ':' . ($second['start'] ?? 0) . '-' . ($second['end'] ?? 0),
                    'lines' => $lines,
                ];
                $clones[] = sprintf(
                    '%s:%d-%d <-> %s:%d-%d (%dL)',
                    $firstName, $first['start'] ?? 0, $first['end'] ?? 0,
                    $secondName, $second['start'] ?? 0, $second['end'] ?? 0,
                    $lines
                );
            }
        }

        $baselineDup = $baseline->get('duplication', 'percentage', 100.0);

        $status = Status::Pass;
        $actions = null;

        if ($percentage > $baselineDup) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::RefactorDup,
                'message' => sprintf('Duplication increased from %.2f%% to %.2f%% — refactor duplicated code', $baselineDup, $percentage),
                'files' => array_slice($clones, 0, 20),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'duplication',
            label: 'Duplication',
            message: sprintf('%.2f%% (baseline: %.2f%%, %d clones)', $percentage, $baselineDup, count($cloneDetails)),
            severity: Severity::Block,
            baseline: ['percentage' => $baselineDup],
            current: ['percentage' => $percentage, 'clones' => count($cloneDetails)],
            actions: $actions,
            details: count($cloneDetails) > 0 ? ['clones' => $cloneDetails] : null,
        );
    }

    private function runPhpcpd(string $phpcpd, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $projectRoot = dirname($baseline->getPath());

        $process = new Process([
            $resolver->resolvePhp(), $phpcpd,
            $projectRoot . '/src',
            $projectRoot . '/app',
        ]);
        $process->run();

        $output = $process->getOutput();
        $clones = [];

        preg_match_all(
            '/(\S+):(\d+)-(\d+).*\n\s*-\s*(\S+):(\d+)-(\d+)/',
            $output,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $clones[] = sprintf('%s:%s-%s <-> %s:%s-%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        }

        $cloneCount = count($clones);
        $baselineDup = $baseline->get('duplication', 'percentage', 100.0);

        $status = $cloneCount > 0 ? Status::Fail : Status::Pass;
        $actions = null;

        if ($cloneCount > 0) {
            $actions = [[
                'type' => ActionType::RefactorDup,
                'message' => sprintf('Found %d duplicated code clones', $cloneCount),
                'files' => array_slice($clones, 0, 20),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'duplication',
            label: 'Duplication',
            message: sprintf('%d clones found (baseline: %.2f%%)', $cloneCount, $baselineDup),
            severity: Severity::Block,
            baseline: ['percentage' => $baselineDup],
            current: ['clones' => $cloneCount],
            actions: $actions,
            details: count($clones) > 0 ? ['clones' => $clones] : null,
        );
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
