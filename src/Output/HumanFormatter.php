<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\Action;
use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;

class HumanFormatter
{
    public function format(CheckResult $result): string
    {
        /** @var array<int, string> $lines */
        $lines = [];

        $lines[] = '';
        $lines[] = $this->box('CATRACA — PHP Quality Gate Report');
        $lines[] = $this->divider();

        foreach ($result->getGates() as $gate) {
            $icon = $this->icon($gate);
            $statusLabel = strtoupper($gate->status->value);
            $lines[] = sprintf(
                '  %s %-24s %-8s %s',
                $icon,
                $gate->label,
                $statusLabel,
                $gate->message
            );
        }

        $lines[] = $this->divider();

        $overall = $result->isPass() ? "\e[32mPASS\e[0m" : "\e[31mFAIL\e[0m";
        $summary = sprintf(
            '  RESULT: %s — %d/%d gates passed',
            $overall,
            $result->getPassedCount(),
            count($result->getGates())
        );
        $lines[] = $summary;
        $lines[] = '';

        $actions = $result->getActions();
        if (count($actions) > 0) {
            $lines[] = $this->box('Required Actions');
            foreach ($actions as $i => $action) {
                $lines[] = sprintf(
                    "  \e[33m[%d]\e[0m \e[1m%s\e[0m — %s",
                    $i + 1,
                    $action->type->value,
                    $action->message
                );
                $this->formatFiles($lines, $action);
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    public function formatPlain(CheckResult $result): string
    {
        /** @var array<int, string> $lines */
        $lines = [];

        $lines[] = '';
        $lines[] = 'CATRACA - PHP Quality Gate Report';
        $lines[] = str_repeat('-', 50);

        foreach ($result->getGates() as $gate) {
            $icon = match ($gate->status) {
                Status::Pass => '[PASS]',
                Status::Fail => '[FAIL]',
                Status::Warn => '[WARN]',
                Status::Skip => '[SKIP]',
            };
            $lines[] = sprintf('  %s %-24s %s', $icon, $gate->label, $gate->message);
        }

        $lines[] = str_repeat('-', 50);
        $lines[] = sprintf(
            '  RESULT: %s — %d/%d gates passed',
            $result->isPass() ? 'PASS' : 'FAIL',
            $result->getPassedCount(),
            count($result->getGates())
        );
        $lines[] = '';

        $actions = $result->getActions();
        if (count($actions) > 0) {
            $lines[] = 'Required Actions:';
            foreach ($actions as $i => $action) {
                $lines[] = sprintf('  [%d] %s — %s', $i + 1, $action->type->value, $action->message);
                foreach (array_slice($action->files, 0, 10) as $file) {
                    $lines[] = sprintf('      -> %s', $file);
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    private function icon(GateResult $gate): string
    {
        return match ($gate->status) {
            Status::Pass => "\e[32m✔\e[0m",
            Status::Fail => "\e[31m✘\e[0m",
            Status::Warn => "\e[33m⚠\e[0m",
            Status::Skip => "\e[90m—\e[0m",
        };
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function formatFiles(array &$lines, Action $action): void
    {
        foreach (array_slice($action->files, 0, 10) as $file) {
            $lines[] = sprintf('      → %s', $file);
        }
        if (count($action->files) > 10) {
            $lines[] = sprintf('      → ... and %d more', count($action->files) - 10);
        }
    }

    private function box(string $text): string
    {
        $len = mb_strlen($text);

        return sprintf("  ┌%s┐\n  │ %s │\n  └%s┘", str_repeat('─', $len + 2), $text, str_repeat('─', $len + 2));
    }

    private function divider(): string
    {
        return '  '.str_repeat('─', 60);
    }
}
