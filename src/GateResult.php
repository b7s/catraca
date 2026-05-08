<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;

readonly class GateResult
{
    /**
     * @param Status $status pass, fail, skip, warn
     * @param string $name Machine key (e.g. "security", "style")
     * @param string $label Human label (e.g. "Security Audit")
     * @param string $message One-line summary
     * @param Severity $severity block or warn
     * @param array|null $baseline Baseline metrics
     * @param array|null $current Current metrics
     * @param array<array{type: Enum\ActionType, message: string, files?: string[]}>|null $actions
     * @param array|null $details Raw tool output / structured details
     */
    public function __construct(
        public Status $status,
        public string $name,
        public string $label,
        public string $message,
        public Severity $severity = Severity::Block,
        public ?array $baseline = null,
        public ?array $current = null,
        public ?array $actions = null,
        public ?array $details = null,
    ) {}

    public function isPass(): bool
    {
        return $this->status === Status::Pass;
    }

    public function isFail(): bool
    {
        return $this->status === Status::Fail;
    }

    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'label' => $this->label,
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
        ];

        if ($this->baseline !== null) {
            $result['baseline'] = $this->baseline;
        }
        if ($this->current !== null) {
            $result['current'] = $this->current;
        }
        if ($this->actions !== null) {
            $result['actions'] = array_map(fn($a) => [
                'type' => $a['type']->value,
                'message' => $a['message'],
                'files' => $a['files'] ?? [],
            ], $this->actions);
        }
        if ($this->details !== null) {
            $result['details'] = $this->details;
        }

        return $result;
    }
}
