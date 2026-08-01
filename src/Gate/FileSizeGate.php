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
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function is_file;
use function is_int;
use function sprintf;

class FileSizeGate implements GateInterface
{
    private const int MAX_LINES = 1000;

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $maxLines = $baseline->getConfig('file_size', 'max_lines', self::MAX_LINES);
        if (!is_int($maxLines) || $maxLines <= 0) {
            $maxLines = self::MAX_LINES;
        }

        $baselineOverLimit = $baseline->getResult('file_size', 'over_limit', 0);
        $baselineOverLimit = is_int($baselineOverLimit) ? $baselineOverLimit : 0;
        /** @var array<int, array{file: string, lines: int, limit: int, excess: int}> $oversized */
        $oversized = [];
        $dirs = (new SourcePathResolver())->resolveForBaseline($baseline);

        foreach ($dirs as $dir) {
            if (is_file($dir)) {
                $this->scanFile($dir, $maxLines, $oversized, $baseline->projectRoot);

                continue;
            }

            if (!is_dir($dir)) {
                continue;
            }
            $this->scanDirectory($dir, $maxLines, $oversized, $baseline->projectRoot);
        }

        $status = Status::Pass;
        $actions = null;

        if (count($oversized) > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::Modularize,
                'message' => sprintf(
                    '%d files exceed %d lines — split into smaller modules',
                    count($oversized),
                    $maxLines,
                ),
                'files' => array_map(static fn(array $f): string => $f['file'] . ' (' . $f['lines'] . 'L)', $oversized),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'file_size',
            label: 'File Size',
            message: sprintf('%d files exceed %d lines', count($oversized), $maxLines),
            severity: Severity::Block,
            baseline: ['max_lines' => $maxLines, 'over_limit' => $baselineOverLimit],
            current: ['over_limit' => count($oversized)],
            actions: $actions,
            details: count($oversized) > 0 ? ['oversized' => $oversized] : null,
        );
    }

    /**
     * @param  array<int, array{file: string, lines: int, limit: int, excess: int}>  $oversized
     */
    private function scanDirectory(string $dir, int $maxLines, array &$oversized, string $root): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $lineCount = 0;
            $handle = @fopen($file->getPathname(), 'r');
            if ($handle === false) {
                continue;
            }
            while (fgets($handle) !== false) {
                $lineCount++;
            }
            fclose($handle);

            if ($lineCount > $maxLines) {
                $oversized[] = [
                    'file' => str_replace($root . '/', '', $file->getPathname()),
                    'lines' => $lineCount,
                    'limit' => $maxLines,
                    'excess' => $lineCount - $maxLines,
                ];
            }
        }
    }

    /**
     * @param  array<int, array{file: string, lines: int, limit: int, excess: int}>  $oversized
     */
    private function scanFile(string $path, int $maxLines, array &$oversized, string $root): void
    {
        $lineCount = 0;
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        while (fgets($handle) !== false) {
            $lineCount++;
        }
        fclose($handle);

        if ($lineCount <= $maxLines) {
            return;
        }

        $oversized[] = [
            'file' => str_replace($root . '/', '', $path),
            'lines' => $lineCount,
            'limit' => $maxLines,
            'excess' => $lineCount - $maxLines,
        ];
    }
}
