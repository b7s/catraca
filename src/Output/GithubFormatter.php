<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;

use function array_slice;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

class GithubFormatter
{
    public function format(CheckResult $result): string
    {
        /** @var array<int, string> $lines */
        $lines = [];

        $lines[] = '::group::Catraca — Quality Gate Report';

        foreach ($result->getGates() as $gate) {
            $icon = match ($gate->status) {
                Status::Pass => '✔',
                Status::Fail => '✘',
                Status::Warn => '⚠',
                Status::Skip => '—',
            };

            $annotationLevel = match ($gate->status) {
                Status::Fail => 'error',
                Status::Warn => 'warning',
                default => 'notice',
            };

            if ($gate->isFail()) {
                $lines[] = sprintf('::%s::%s: %s', $annotationLevel, $gate->label, $gate->message);
            }

            $lines[] = sprintf('%s %s: %s', $icon, $gate->label, $gate->message);

            if ($gate->details !== null) {
                $this->annotateDetails($lines, $gate, $annotationLevel);
            }
        }

        $lines[] = '';
        $overall = $result->isPass() ? 'PASS' : 'FAIL';
        $lines[] = sprintf('Result: %s (%d/%d gates passed)', $overall, $result->getPassedCount(), count($result->getGates()));

        $actions = $result->getActions();
        if (count($actions) > 0) {
            $lines[] = '';
            $lines[] = 'Required Actions:';
            foreach ($actions as $i => $action) {
                $lines[] = sprintf('  [%d] %s — %s', $i + 1, $action->type->value, $action->message);
                $reasons = $action->reasons;
                foreach (array_slice($action->files, 0, 10) as $j => $file) {
                    $reason = isset($reasons[$j]) && $reasons[$j] !== '' ? ' — '.$reasons[$j] : '';
                    $lines[] = sprintf('    - %s%s', $file, $reason);
                }
            }
        }

        $lines[] = '::endgroup::';

        if (! $result->isPass()) {
            $lines[] = sprintf('::error::Catraca: %d quality gate(s) failed', $result->getFailedCount());
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function annotateDetails(array &$lines, GateResult $gate, string $annotationLevel): void
    {
        $errors = $gate->details['errors'] ?? $gate->details['clones'] ?? $gate->details['oversized'] ?? [];
        if (! is_array($errors)) {
            return;
        }
        foreach (array_slice($errors, 0, 10) as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $file = $detail['file'] ?? null;
            $line = $detail['line'] ?? 0;
            $message = $detail['message'] ?? ($detail['file'] ?? '');
            if (is_string($file) && is_int($line) && is_string($message)) {
                $lines[] = sprintf('::%s file=%s,line=%d::%s', $annotationLevel, $file, $line, $message);
            }
        }
    }
}
