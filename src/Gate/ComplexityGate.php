<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use RuntimeException;
use Symfony\Component\Process\Process;

use function array_slice;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

class ComplexityGate implements GateInterface
{
    private const int BLOCK_AT = 50;

    private const int WARN_AT = 20;

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

        $tmpDir = sys_get_temp_dir() . '/catraca-' . uniqid('', true);
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
        }

        $jsonPath = $tmpDir . '/phpmetrics.json';

        $process = new Process([
            $resolver->resolvePhp(),
            $phpmetrics,
            '--report-json=' . $jsonPath,
            ...(new SourcePathResolver())->resolveForBaseline($baseline),
        ], timeout: $baseline->getGateTimeout('complexity'));
        $process->run();

        $data = null;
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            if (is_string($content)) {
                /** @var mixed $decoded */
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
            /** @var array<string, mixed> $data */
            $classes = $data['classes'] ?? [];
            if (is_array($classes)) {
                /** @var array<string, mixed> $classes */
                $this->extractMethods($classes, $violations, $warnings, $maxCcn, static fn(
                    mixed $key,
                    mixed $data,
                ): array => [
                    is_string($key) ? $key : 'unknown',
                    $data,
                ]);
            }
            /** @var array<string, mixed> $data */
            $files = $data['files'] ?? [];
            if (is_array($files)) {
                /** @var array<string, mixed> $files */
                $this->extractMethods($files, $violations, $warnings, $maxCcn, static fn(
                    mixed $key,
                    mixed $data,
                ): array => [
                    is_array($data) && is_string($data['name'] ?? null) ? $data['name'] : 'unknown',
                    is_array($data) ? $data : [],
                ]);
            }
        }

        $baselineMaxCcn = $baseline->getIntResult('complexity', 'max_ccn', 0);
        $blockAt = $baseline->getIntConfig('complexity', 'block_at', self::BLOCK_AT);
        $warnAt = $baseline->getIntConfig('complexity', 'warn_at', self::WARN_AT);

        $status = Status::Pass;
        $actions = null;

        if (count($violations) > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::Modularize,
                'message' => sprintf('%d methods exceed CCN %d (block threshold)', count($violations), $blockAt),
                'files' => array_map(
                    static fn(array $v): string => $v['file'] . ':' . $v['method'] . ' (CCN ' . $v['ccn'] . ')',
                    $violations,
                ),
            ]];
        }

        $warnFiles = array_map(
            static fn(array $w): string => $w['file'] . ':' . $w['method'] . ' (CCN ' . $w['ccn'] . ')',
            $warnings,
        );

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
                $warnAt,
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
     * @param  array<string, mixed>  $items
     * @param  array<int, array{file: string, method: string, ccn: int}>  $violations
     * @param  array<int, array{file: string, method: string, ccn: int}>  $warnings
     * @param  callable(mixed $key, mixed $methodData): array{string, mixed}  $extractNameAndData
     */
    private function extractMethods(
        /** @param array<string, mixed> $items */
        array $items,
        array &$violations,
        array &$warnings,
        int &$maxCcn,
        callable $extractNameAndData,
    ): void {
        foreach ($items as $key => $itemData) {
            if (!is_array($itemData)) {
                continue;
            }
            /** @var mixed $methodsRaw */
            $methodsRaw = $itemData['methods'] ?? [];
            $methods = is_array($methodsRaw) ? $methodsRaw : [];
            foreach ($methods as $methodKey => $methodData) {
                [$name, $data] = $extractNameAndData($methodKey, $methodData);
                $this->processMethod($key, $name, $data, $violations, $warnings, $maxCcn);
            }
        }
    }

    /**
     * @param  array<int, array{file: string, method: string, ccn: int}>  $violations
     * @param  array<int, array{file: string, method: string, ccn: int}>  $warnings
     */
    private function processMethod(
        string $file,
        string $name,
        mixed $methodData,
        array &$violations,
        array &$warnings,
        int &$maxCcn,
    ): void {
        if (!is_array($methodData)) {
            return;
        }
        $ccn = is_int($methodData['ccn'] ?? null) ? $methodData['ccn'] : 1;
        if ($ccn > $maxCcn) {
            $maxCcn = $ccn;
        }
        $entry = ['file' => $file, 'method' => $name, 'ccn' => $ccn];
        if ($ccn >= self::BLOCK_AT) {
            $violations[] = $entry;
        } elseif ($ccn >= self::WARN_AT) {
            $warnings[] = $entry;
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
