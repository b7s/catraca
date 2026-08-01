<?php

declare(strict_types=1);

namespace B7S\Catraca\Output;

use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;

use function count;
use function sprintf;
use function str_repeat;
use function strtoupper;

class HumanFormatter
{
    use ActionRenderer;
    use BoxDrawer;

    public function format(CheckResult $result): string
    {
        /** @var array<int, string> $lines */
        $lines = [];

        $lines[] = '';
        $lines[] = $this->box('CATRACA — PHP Quality Gate Report');
        $lines[] = $this->divider();

        foreach ($result->getGates() as $gate) {
            $lines[] = sprintf(
                ' %s %-24s %-8s %s',
                $this->icon($gate),
                $gate->label,
                strtoupper($gate->status->value),
                $gate->message,
            );
        }

        $this->appendSummary($lines, $result, includeDivider: true);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Used after the live table, which already contains every gate row.
     */
    public function formatSummary(CheckResult $result): string
    {
        $lines = [];
        $this->appendSummary($lines, $result, includeDivider: false);

        return implode("\n", $lines) . "\n";
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
                Status::Cancelled => '[CANCELLED]',
            };
            $lines[] = sprintf(' %s %-24s %s', $icon, $gate->label, $gate->message);
        }

        $lines[] = str_repeat('-', 50);
        $lines[] = sprintf(
            ' RESULT: %s — %d/%d gates passed',
            $result->isPass() ? 'PASS' : 'FAIL',
            $result->getPassedCount(),
            count($result->getGates()),
        );
        $lines[] = '';

        $actions = $result->getActions();
        if (count($actions) > 0) {
            $lines[] = 'Required Actions:';
            foreach ($actions as $index => $action) {
                $lines[] = sprintf(' [%d] %s — %s', $index + 1, $action->type->value, $action->message);
                $this->appendActionFilesMultiLine($lines, $action, ' -> ', '    ');
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendSummary(array &$lines, CheckResult $result, bool $includeDivider): void
    {
        if ($includeDivider) {
            $lines[] = $this->divider();
        }

        $overall = $result->isPass() ? "\e[32mPASS\e[0m" : "\e[31mFAIL\e[0m";
        $lines[] = sprintf(
            ' RESULT: %s — %d/%d gates passed',
            $overall,
            $result->getPassedCount(),
            count($result->getGates()),
        );
        $lines[] = '';

        $actions = $result->getActions();
        if (count($actions) === 0) {
            return;
        }

        $lines[] = $this->box('Required Actions');
        foreach ($actions as $index => $action) {
            $lines[] = sprintf(
                " \e[33m[%d]\e[0m \e[1m%s\e[0m — %s",
                $index + 1,
                $action->type->value,
                $action->message,
            );
            $this->appendActionFilesMultiLine($lines, $action, ' → ', '   ');
        }
        $lines[] = '';
    }

    private function icon(GateResult $gate): string
    {
        return match ($gate->status) {
            Status::Pass => "\e[32m✔\e[0m",
            Status::Fail => "\e[31m⛔\e[0m",
            Status::Warn => "\e[33m⚠\e[0m",
            Status::Skip => "\e[90m—\e[0m",
            Status::Cancelled => "\e[31m⊘\e[0m",
        };
    }
}
