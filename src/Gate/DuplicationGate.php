<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

class DuplicationGate implements GateInterface
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
        $tmpDir = sys_get_temp_dir().'/catraca-'.uniqid('', true);
        if (! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
        }

        $process = new Process([
            $jscpd,
            '--threshold', '0',
            '--reporters', 'json',
            '--output', $tmpDir,
            '--ignore', '**/vendor/**,**/node_modules/**',
            $baseline->projectRoot.'/src',
            $baseline->projectRoot.'/app',
        ]);
        $process->run();

        $data = $this->readJsonFile($tmpDir.'/jscpd-report.json');
        $this->cleanup($tmpDir);

        return $this->parseJscpdResult($data, $baseline);
    }

    private function runJscpdViaNpx(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $tmpDir = sys_get_temp_dir().'/catraca-'.uniqid('', true);
        if (! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
        }

        $process = new Process([
            'npx', 'jscpd',
            '--threshold', '0',
            '--reporters', 'json',
            '--output', $tmpDir,
            '--ignore', '**/vendor/**,**/node_modules/**',
            $baseline->projectRoot.'/src',
            $baseline->projectRoot.'/app',
        ]);
        $process->run();

        $data = $this->readJsonFile($tmpDir.'/jscpd-report.json');
        $this->cleanup($tmpDir);

        return $this->parseJscpdResult($data, $baseline);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function parseJscpdResult(?array $data, Baseline $baseline): GateResult
    {
        $percentage = 0.0;
        /** @var array<int, string> $clones */
        $clones = [];
        /** @var array<int, array{file_a: string, file_b: string, lines: int}> $cloneDetails */
        $cloneDetails = [];

        if ($data !== null) {
            $statistics = is_array($data['statistics'] ?? null) ? $data['statistics'] : $data;
            $rawTotal = $statistics['total'] ?? null;
            if (is_array($rawTotal) && is_numeric($rawTotal['percentage'] ?? null)) {
                $percentage = (float) $rawTotal['percentage'];
            } elseif (is_numeric($statistics['percentage'] ?? null)) {
                $percentage = (float) $statistics['percentage'];
            }

            $duplicates = $data['duplicates'] ?? ($statistics['duplicates'] ?? []);
            if (is_array($duplicates)) {
                foreach ($duplicates as $dup) {
                    if (! is_array($dup)) {
                        continue;
                    }
                    $first = is_array($dup['firstFile'] ?? null) ? $dup['firstFile'] : [];
                    $second = is_array($dup['secondFile'] ?? null) ? $dup['secondFile'] : [];

                    $firstName = is_string($first['name'] ?? null) ? $first['name'] : (is_string($first['path'] ?? null) ? $first['path'] : 'unknown');
                    $secondName = is_string($second['name'] ?? null) ? $second['name'] : (is_string($second['path'] ?? null) ? $second['path'] : 'unknown');
                    $lines = is_int($dup['lines'] ?? null) ? $dup['lines'] : 0;
                    $firstStart = is_int($first['start'] ?? null) ? $first['start'] : 0;
                    $firstEnd = is_int($first['end'] ?? null) ? $first['end'] : 0;
                    $secondStart = is_int($second['start'] ?? null) ? $second['start'] : 0;
                    $secondEnd = is_int($second['end'] ?? null) ? $second['end'] : 0;

                    $cloneDetails[] = [
                        'file_a' => $firstName.':'.$firstStart.'-'.$firstEnd,
                        'file_b' => $secondName.':'.$secondStart.'-'.$secondEnd,
                        'lines' => $lines,
                    ];
                    $clones[] = sprintf(
                        '%s:%d-%d <-> %s:%d-%d (%dL)',
                        $firstName, $firstStart, $firstEnd,
                        $secondName, $secondStart, $secondEnd,
                        $lines
                    );
                }
            }
        }

        $baselineDup = $this->getBaselineDup($baseline);

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
        $process = new Process([
            $resolver->resolvePhp(), $phpcpd,
            $baseline->projectRoot.'/src',
            $baseline->projectRoot.'/app',
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
        $baselineDup = $this->getBaselineDup($baseline);

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

    private function getBaselineDup(Baseline $baseline): float
    {
        $val = $baseline->get('duplication', 'percentage', 2.0);

        return is_numeric($val) ? (float) $val : 2.0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        return array_filter($data, static fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if (! ($item instanceof SplFileInfo)) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
