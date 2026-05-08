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

class ComplexityGate implements GateInterface
{
    private const BLOCK_AT = 50;

    private const WARN_AT = 20;

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $phpmetrics = $resolver->resolve('phpmetrics');
        if ($phpmetrics === null) {
            return new GateResult(
                status: Status::Skip,
                name: 'complexity',
                label: 'Cyclomatic Complexity',
                message: 'phpmetrics not found (install phpmetrics/phpmetrics)',
                severity: Severity::Warn,
            );
        }

        $tmpDir = sys_get_temp_dir().'/catraca-'.uniqid();
        @mkdir($tmpDir, 0755, true);

        $jsonPath = $tmpDir.'/phpmetrics.json';

        $process = new Process([
            $resolver->resolvePhp(), $phpmetrics,
            '--report-json='.$jsonPath,
            $baseline->projectRoot.'/src',
            $baseline->projectRoot.'/app',
        ]);
        $process->run();

        $data = null;
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            if (is_string($content)) {
                $decoded = json_decode($content, true);
                $data = is_array($decoded) ? $decoded : null;
            }
        }

        $this->cleanup($tmpDir);

        /** @var array<string, mixed>|null $data */
        return $this->parseResult($data, $baseline);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function parseResult(?array $data, Baseline $baseline): GateResult
    {
        /** @var array<int, array{file: string, method: string, ccn: int}> $violations */
        $violations = [];
        /** @var array<int, array{file: string, method: string, ccn: int}> $warnings */
        $warnings = [];
        $maxCcn = 0;

        if ($data !== null) {
            $classes = $data['classes'] ?? [];
            if (is_array($classes)) {
                /** @var array<string, mixed> $classes */
                $this->extractFromClasses($classes, $violations, $warnings, $maxCcn);
            }
            $files = $data['files'] ?? [];
            if (is_array($files)) {
                /** @var array<string, mixed> $files */
                $this->extractFromFiles($files, $violations, $warnings, $maxCcn);
            }
        }

        $baselineMaxCcn = $baseline->get('complexity', 'max_ccn', 0);
        if (! is_int($baselineMaxCcn)) {
            $baselineMaxCcn = 0;
        }
        $blockAt = is_int($baseline->get('complexity', 'block_at', self::BLOCK_AT))
            ? $baseline->get('complexity', 'block_at', self::BLOCK_AT)
            : self::BLOCK_AT;
        $warnAt = is_int($baseline->get('complexity', 'warn_at', self::WARN_AT))
            ? $baseline->get('complexity', 'warn_at', self::WARN_AT)
            : self::WARN_AT;

        $status = Status::Pass;
        $actions = null;

        if (count($violations) > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::Modularize,
                'message' => sprintf(
                    '%d methods exceed CCN %d (block threshold)',
                    count($violations),
                    $blockAt
                ),
                'files' => array_map(fn (array $v): string => $v['file'].':'.$v['method'].' (CCN '.$v['ccn'].')', $violations),
            ]];
        }

        $warnFiles = array_map(fn (array $w): string => $w['file'].':'.$w['method'].' (CCN '.$w['ccn'].')', $warnings);

        return new GateResult(
            status: $status,
            name: 'complexity',
            label: 'Cyclomatic Complexity',
            message: sprintf(
                'max CCN %d, %d violations (>%d), %d warnings (>%d)',
                $maxCcn,
                count($violations),
                $blockAt,
                count($warnings),
                $warnAt
            ),
            severity: Severity::Block,
            baseline: ['max_ccn' => $baselineMaxCcn],
            current: ['max_ccn' => $maxCcn, 'violations' => count($violations), 'warnings' => count($warnings)],
            actions: $actions,
            details: [
                'violations' => $violations,
                'warnings' => array_slice($warnings, 0, 20),
                'warning_files' => array_slice($warnFiles, 0, 20),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $classes
     * @param  array<int, array{file: string, method: string, ccn: int}>  $violations
     * @param  array<int, array{file: string, method: string, ccn: int}>  $warnings
     */
    private function extractFromClasses(array $classes, array &$violations, array &$warnings, int &$maxCcn): void
    {
        foreach ($classes as $fqcn => $classData) {
            if (! is_array($classData)) {
                continue;
            }
            $methods = $classData['methods'] ?? [];
            if (! is_array($methods)) {
                continue;
            }
            foreach ($methods as $methodName => $methodData) {
                if (! is_array($methodData)) {
                    continue;
                }
                $ccn = is_int($methodData['ccn'] ?? null) ? $methodData['ccn'] : 1;
                if ($ccn > $maxCcn) {
                    $maxCcn = $ccn;
                }
                $entry = ['file' => $fqcn, 'method' => is_string($methodName) ? $methodName : 'unknown', 'ccn' => $ccn];
                if ($ccn >= self::BLOCK_AT) {
                    $violations[] = $entry;
                } elseif ($ccn >= self::WARN_AT) {
                    $warnings[] = $entry;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $files
     * @param  array<int, array{file: string, method: string, ccn: int}>  $violations
     * @param  array<int, array{file: string, method: string, ccn: int}>  $warnings
     */
    private function extractFromFiles(array $files, array &$violations, array &$warnings, int &$maxCcn): void
    {
        foreach ($files as $filePath => $fileData) {
            if (! is_array($fileData)) {
                continue;
            }
            $methods = $fileData['methods'] ?? [];
            if (! is_array($methods)) {
                continue;
            }
            foreach ($methods as $method) {
                if (! is_array($method)) {
                    continue;
                }
                $ccn = is_int($method['ccn'] ?? null) ? $method['ccn'] : 1;
                $name = is_string($method['name'] ?? null) ? $method['name'] : 'unknown';
                if ($ccn > $maxCcn) {
                    $maxCcn = $ccn;
                }
                $entry = ['file' => $filePath, 'method' => $name, 'ccn' => $ccn];
                if ($ccn >= self::BLOCK_AT) {
                    $violations[] = $entry;
                } elseif ($ccn >= self::WARN_AT) {
                    $warnings[] = $entry;
                }
            }
        }
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
