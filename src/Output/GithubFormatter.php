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
    use ActionRenderer;

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
                Status::Cancelled => '�',
            };

            $annotationLevel = match ($gate->status) {
                Status::Fail, Status::Cancelled => 'error',
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
        $lines[] = sprintf(
            'Result: %s (%d/%d gates passed)',
            $overall,
            $result->getPassedCount(),
            count($result->getGates()),
        );

        $actions = $result->getActions();
        if (count($actions) > 0) {
            $lines[] = '';
            $lines[] = 'Required Actions:';
            foreach ($actions as $i => $action) {
                $lines[] = sprintf(' [%d] %s — %s', $i + 1, $action->type->value, $action->message);
                $this->appendActionFilesInline($lines, $action, ' - ', ' — ');
            }
        }

        $lines[] = '::endgroup::';

        if (!$result->isPass()) {
            $lines[] = sprintf('::error::Catraca: %d quality gate(s) failed', $result->getFailedCount());
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function annotateDetails(array &$lines, GateResult $gate, string $annotationLevel): void
    {
        /** @var mixed $details */
        $details = $gate->details;
        $errors = $details['errors'] ?? $details['clones'] ?? $details['oversized'] ?? [];
        if (!is_array($errors)) {
            return;
        }
        foreach (array_slice($errors, 0, 10) as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            /** @var mixed $fileRaw */
            $fileRaw = $detail['file'] ?? null;
            /** @var mixed $lineRaw */
            $lineRaw = $detail['line'] ?? 0;
            /** @var mixed $messageRaw */
            $messageRaw = $detail['message'] ?? $detail['file'] ?? '';
            $file = is_string($fileRaw) ? $fileRaw : null;
            $line = is_int($lineRaw) ? $lineRaw : 0;
            $message = is_string($messageRaw) ? $messageRaw : '';
            if ($file !== null && is_int($line) && is_string($message)) {
                $lines[] = sprintf('::%s file=%s,line=%d::%s', $annotationLevel, $file, $line, $message);
            }
        }
    }
}
