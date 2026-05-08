<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;

readonly class GateResult
{
    /**
     * @param array<mixed, mixed>|null $baseline
     * @param array<mixed, mixed>|null $current
     * @param array<array{type: ActionType, message: string, files?: array<int, string>}>|null $actions
     * @param array<mixed, mixed>|null $details
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

    /**
     * @return array<string, mixed>
     */
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
            $result['actions'] = array_map(fn(array $a): array => [
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
