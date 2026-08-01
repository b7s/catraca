<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\Status;
use DateTimeImmutable;
use DateTimeInterface;

use function count;
use function is_array;
use function is_string;

class CheckResult
{
    /** @var array<int, GateResult> */
    private array $gates = [];

    public function __construct(
        public readonly DateTimeImmutable $timestamp = new DateTimeImmutable(),
    ) {}

    public function add(GateResult $gate): void
    {
        $this->gates[] = $gate;
    }

    /** @return array<int, GateResult> */
    public function getGates(): array
    {
        return $this->gates;
    }

    public function isPass(): bool
    {
        foreach ($this->gates as $gate) {
            if ($gate->isFail()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, Action> */
    public function getActions(): array
    {
        $actions = [];
        foreach ($this->gates as $gate) {
            if ($gate->actions === null) {
                continue;
            }
            $gateReasons = $this->extractReasons($gate->details);
            foreach ($gate->actions as $actionData) {
                $actionReasons = $actionData['reasons'] ?? [];
                $reasons = count($actionReasons) > 0 ? $actionReasons : $gateReasons;
                $actions[] = new Action(
                    type: $actionData['type'],
                    message: $actionData['message'],
                    files: $actionData['files'] ?? [],
                    priority: count($actions),
                    reasons: $reasons,
                );
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<int, string>
     */
    private function extractReasons(?array $details): array
    {
        if ($details === null) {
            return [];
        }

        $reasons = [];

        foreach (['errors', 'clones', 'oversized'] as $key) {
            $items = $details[$key] ?? null;
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item) && isset($item['message']) && is_string($item['message'])) {
                    $reasons[] = $item['message'];
                }
            }
        }

        if (empty($reasons)) {
            foreach ($details as $item) {
                if (is_array($item) && isset($item['title']) && is_string($item['title'])) {
                    $reasons[] = $item['title'];
                }
            }
        }

        return $reasons;
    }

    public function getPassedCount(): int
    {
        return count(array_filter($this->gates, static fn(GateResult $g): bool => $g->isPass()));
    }

    public function getFailedCount(): int
    {
        return count(array_filter($this->gates, static fn(GateResult $g): bool => $g->isFail()));
    }

    public function getSkippedCount(): int
    {
        return count(array_filter($this->gates, static fn(GateResult $g): bool => $g->status === Status::Skip));
    }

    /** @return array{
     *     schema: string,
     *     type: string,
     *     result: string,
     *     timestamp: string,
     *     summary: array{total: int, passed: int, failed: int, skipped: int},
     *     gates: array<int, array<string, mixed>>,
     *     actions: array<int, array{type: string, priority: int, message: string, files: array<int, string>, reasons: array<int, string>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema' => Baseline::SCHEMA,
            'type' => 'check',
            'result' => $this->isPass() ? 'pass' : 'fail',
            'timestamp' => $this->timestamp->format(DateTimeInterface::ATOM),
            'summary' => [
                'total' => count($this->gates),
                'passed' => $this->getPassedCount(),
                'failed' => $this->getFailedCount(),
                'skipped' => $this->getSkippedCount(),
            ],
            'gates' => array_map(static fn(GateResult $g): array => $g->toArray(), $this->gates),
            'actions' => array_map(static fn(Action $a): array => $a->toArray(), $this->getActions()),
        ];
    }
}
