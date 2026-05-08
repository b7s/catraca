<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;

class FileSizeGate
{
    private const MAX_LINES = 1000;

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $projectRoot = dirname($baseline->getPath());
        $maxLines = $baseline->get('file_size', 'max_lines', self::MAX_LINES) ?: self::MAX_LINES;

        $oversized = [];
        $dirs = [$projectRoot . '/src', $projectRoot . '/app', $projectRoot . '/lib'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $this->scanDirectory($dir, $maxLines, $oversized, $projectRoot);
        }

        $status = Status::Pass;
        $actions = null;

        if (count($oversized) > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::Modularize,
                'message' => sprintf('%d files exceed %d lines — split into smaller modules', count($oversized), $maxLines),
                'files' => array_map(fn($f) => $f['file'] . ' (' . $f['lines'] . 'L)', $oversized),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'file_size',
            label: 'File Size',
            message: sprintf('%d files exceed %d lines', count($oversized), $maxLines),
            severity: Severity::Block,
            baseline: ['max_lines' => $maxLines],
            current: ['over_limit' => count($oversized)],
            actions: $actions,
            details: count($oversized) > 0 ? ['oversized' => $oversized] : null,
        );
    }

    private function scanDirectory(string $dir, int $maxLines, array &$oversized, string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
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
}
