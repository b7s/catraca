<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;
use Symfony\Component\Process\Process;

class ComplexityGate
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

        $tmpDir = sys_get_temp_dir() . '/catraca-' . uniqid();
        @mkdir($tmpDir, 0755, true);

        $projectRoot = dirname($baseline->getPath());
        $jsonPath = $tmpDir . '/phpmetrics.json';

        $process = new Process([
            $resolver->resolvePhp(), $phpmetrics,
            '--report-json=' . $jsonPath,
            $projectRoot . '/src',
            $projectRoot . '/app',
        ]);
        $process->run();

        $data = null;
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            $data = json_decode($content, true);
        }

        $this->cleanup($tmpDir);

        return $this->parseResult($data, $baseline);
    }

    private function parseResult(?array $data, Baseline $baseline): GateResult
    {
        $violations = [];
        $warnings = [];
        $maxCcn = 0;

        if ($data !== null) {
            $this->extractFromClasses($data['classes'] ?? [], $violations, $warnings, $maxCcn);
            $this->extractFromFiles($data['files'] ?? [], $violations, $warnings, $maxCcn);
        }

        $baselineMaxCcn = $baseline->get('complexity', 'max_ccn', 0);
        $blockAt = $baseline->get('complexity', 'block_at', self::BLOCK_AT);
        $warnAt = $baseline->get('complexity', 'warn_at', self::WARN_AT);

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
                'files' => array_map(fn($v) => $v['file'] . ':' . $v['method'] . ' (CCN ' . $v['ccn'] . ')', $violations),
            ]];
        }

        $warnFiles = array_map(fn($w) => $w['file'] . ':' . $w['method'] . ' (CCN ' . $w['ccn'] . ')', $warnings);

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

    private function extractFromClasses(array $classes, array &$violations, array &$warnings, int &$maxCcn): void
    {
        foreach ($classes as $fqcn => $classData) {
            $methods = $classData['methods'] ?? [];
            foreach ($methods as $methodName => $methodData) {
                $ccn = (int) ($methodData['ccn'] ?? 1);
                if ($ccn > $maxCcn) {
                    $maxCcn = $ccn;
                }
                if ($ccn >= self::BLOCK_AT) {
                    $violations[] = ['file' => $fqcn, 'method' => $methodName, 'ccn' => $ccn];
                } elseif ($ccn >= self::WARN_AT) {
                    $warnings[] = ['file' => $fqcn, 'method' => $methodName, 'ccn' => $ccn];
                }
            }
        }
    }

    private function extractFromFiles(array $files, array &$violations, array &$warnings, int &$maxCcn): void
    {
        foreach ($files as $filePath => $fileData) {
            $methods = $fileData['methods'] ?? [];
            foreach ($methods as $method) {
                $ccn = (int) ($method['ccn'] ?? 1);
                $name = $method['name'] ?? 'unknown';
                if ($ccn > $maxCcn) {
                    $maxCcn = $ccn;
                }
                if ($ccn >= self::BLOCK_AT) {
                    $violations[] = ['file' => $filePath, 'method' => $name, 'ccn' => $ccn];
                } elseif ($ccn >= self::WARN_AT) {
                    $warnings[] = ['file' => $filePath, 'method' => $name, 'ccn' => $ccn];
                }
            }
        }
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
