<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;

use function is_array;
use function is_string;

readonly class GateResult
{
    /**
     * @param  array<string, mixed>|null  $baseline
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $details
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
        return $this->status === Status::Fail || $this->status === Status::Cancelled;
    }

    /** @param array<array-key, mixed> $data */
    public static function isSerializedData(array $data): bool
    {
        foreach (['status', 'name', 'label', 'message', 'severity'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                return false;
            }
        }

        /** @var mixed $status */
        $status = $data['status'] ?? null;
        /** @var mixed $severity */
        $severity = $data['severity'] ?? null;
        if (!is_string($status) || !is_string($severity)) {
            return false;
        }
        if (Status::tryFrom($status) === null || Severity::tryFrom($severity) === null) {
            return false;
        }

        foreach (['baseline', 'current', 'details'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                return false;
            }
        }

        /** @var mixed $actions */
        $actions = $data['actions'] ?? null;
        if ($actions === null) {
            return true;
        }
        if (!is_array($actions)) {
            return false;
        }

        foreach ($actions as $action) {
            if (!is_array($action) || !is_string($action['type'] ?? null) || !is_string($action['message'] ?? null)) {
                return false;
            }
            if (ActionType::tryFrom($action['type']) === null) {
                return false;
            }
            /** @var mixed $files */
            $files = $action['files'] ?? [];
            /** @var mixed $reasons */
            $reasons = $action['reasons'] ?? [];
            if (!self::isStringList($files) || !self::isStringList($reasons)) {
                return false;
            }
        }

        return true;
    }

    private static function isStringList(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *     status: string,
     *     name: string,
     *     label: string,
     *     message: string,
     *     severity: string,
     *     baseline?: array<string, mixed>|null,
     *     current?: array<string, mixed>|null,
     *     actions?: array<int, array{type: string, message: string, files?: array<int, string>, reasons?: array<int, string>}>|null,
     *     details?: array<string, mixed>|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        /** @var mixed $actionsRaw */
        $actionsRaw = $data['actions'] ?? null;
        /** @var array<int, array{type: ActionType, message: string, files?: array<int, string>, reasons?: array<int, string>}>|null $actions */
        $actions = null;
        if (isset($data['actions']) && is_array($actionsRaw)) {
            $actions = array_map(static fn(array $a): array => [
                'type' => ActionType::from(is_string($a['type'] ?? null) ? $a['type'] : ''),
                'message' => $a['message'] ?? '',
                'files' => $a['files'] ?? [],
                'reasons' => $a['reasons'] ?? [],
            ], $actionsRaw);
        }

        return new self(
            status: Status::from($data['status']),
            name: $data['name'],
            label: $data['label'],
            message: $data['message'],
            severity: Severity::from($data['severity']),
            baseline: $data['baseline'] ?? null,
            current: $data['current'] ?? null,
            // @mago-ignore analysis:less-specific-nested-argument-type
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
        } else {
            $result['baseline'] = [];
        }

        if ($this->current !== null) {
            $result['current'] = $this->current;
        } else {
            $result['current'] = [];
        }

        if ($this->actions !== null) {
            $result['actions'] = array_map(static fn(array $a): array => [
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
