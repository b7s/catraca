<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\Action;

use function array_slice;
use function count;
use function sprintf;

trait ActionRenderer
{
    /**
     * @param  array<int, string>  $lines
     */
    private function appendActionFilesInline(array &$lines, Action $action, string $filePrefix, string $reasonSep): void
    {
        $reasons = $action->reasons;
        foreach (array_slice($action->files, 0, 10) as $j => $file) {
            $reason = isset($reasons[$j]) && $reasons[$j] !== '' ? $reasonSep . $reasons[$j] : '';
            $lines[] = sprintf('%s%s%s', $filePrefix, $file, $reason);
        }
        if (count($action->files) > 10) {
            $lines[] = sprintf('%s... and %d more', $filePrefix, count($action->files) - 10);
        }
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendActionFilesMultiLine(
        array &$lines,
        Action $action,
        string $filePrefix,
        string $reasonPrefix,
    ): void {
        $reasons = $action->reasons;
        foreach (array_slice($action->files, 0, 10) as $j => $file) {
            $lines[] = sprintf('%s%s', $filePrefix, $file);
            if (isset($reasons[$j]) && $reasons[$j] !== '') {
                $lines[] = sprintf('%s%s', $reasonPrefix, $reasons[$j]);
            }
        }
        if (count($action->files) > 10) {
            $lines[] = sprintf('%s... and %d more', $filePrefix, count($action->files) - 10);
        }
    }
}
