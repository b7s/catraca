<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;

use function is_array;

readonly class GateResult
{
    /**
     * @param  array<array{type: ActionType, message: string, files?: array<int, string>, reasons?: array<int, string>}>|null  $actions
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

    public static function fromArray(array $data): self
    {
        $actions = null;
        if (isset($data['actions']) && is_array($data['actions'])) {
            $actions = array_map(static fn (array $a): array => [
                'type' => ActionType::from($a['type']),
                'message' => $a['message'],
                'files' => $a['files'] ?? [],
                'reasons' => $a['reasons'] ?? [],
            ], $data['actions']);
        }

        return new self(
            status: Status::from($data['status']),
            name: $data['name'],
            label: $data['label'],
            message: $data['message'],
            severity: Severity::from($data['severity']),
            baseline: $data['baseline'] ?? null,
            current: $data['current'] ?? null,
            actions: $actions,
            details: $data['details'] ?? null,
        );
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
            $result['actions'] = array_map(static fn (array $a): array => [
                'type' => $a['type']->value,
                'message' => $a['message'],
                'files' => $a['files'] ?? [],
                'reasons' => $a['reasons'] ?? [],
            ], $this->actions);
        }
        if ($this->details !== null) {
            $result['details'] = $this->details;
        }

        return $result;
    }
}
