<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\Status;

class GithubFormatter
{
    public function format(CheckResult $result): string
    {
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
                $errors = $gate->details['errors'] ?? $gate->details['clones'] ?? $gate->details['oversized'] ?? [];
                foreach (array_slice((array) $errors, 0, 10) as $detail) {
                    if (isset($detail['file'])) {
                        $line = $detail['line'] ?? 0;
                        $message = $detail['message'] ?? ($detail['file'] ?? '');
                        $lines[] = sprintf('::%s file=%s,line=%d::%s', $annotationLevel, $detail['file'], $line, $message);
                    }
                }
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
            }
        }

        $lines[] = '::endgroup::';

        if (!$result->isPass()) {
            $lines[] = sprintf('::error::Catraca: %d quality gate(s) failed', $result->getFailedCount());
        }

        return implode("\n", $lines) . "\n";
    }
}
