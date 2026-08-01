<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\Fixer\FixerResult;
use B7S\Catraca\FixResult;

use function sprintf;

class FixHumanFormatter
{
    use BoxDrawer;

    public function format(FixResult $result): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = $this->box('CATRACA — Auto-Fix Report');
        $lines[] = $this->divider();

        foreach ($result->getFixers() as $fixer) {
            $icon = $this->icon($fixer);
            $lines[] = sprintf('  %s %-30s %s', $icon, $fixer->label, $fixer->message);
        }

        $lines[] = $this->divider();
        $lines[] = sprintf(
            '  Fixed: %d | Skipped: %d | Errors: %d',
            $result->getFixedCount(),
            $result->getSkippedCount(),
            $result->getErrorCount(),
        );
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    public function formatPlain(FixResult $result): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = 'CATRACA - Auto-Fix Report';
        $lines[] = str_repeat('-', 50);

        foreach ($result->getFixers() as $fixer) {
            $icon = $fixer->fixed ? '[OK]' : ($fixer->skipped ? '[SKIP]' : '[ERR]');
            $lines[] = sprintf('  %s %-30s %s', $icon, $fixer->label, $fixer->message);
        }

        $lines[] = str_repeat('-', 50);
        $lines[] = sprintf(
            'Fixed: %d | Skipped: %d | Errors: %d',
            $result->getFixedCount(),
            $result->getSkippedCount(),
            $result->getErrorCount(),
        );
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    private function icon(FixerResult $fixer): string
    {
        if ($fixer->fixed) {
            return "\e[32m✔\e[0m";
        }
        if ($fixer->skipped) {
            return "\e[90m—\e[0m";
        }

        return "\e[31m✘\e[0m";
    }
}
