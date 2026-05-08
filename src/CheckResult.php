<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\Status;

class CheckResult
{
    /** @var array<int, GateResult> */
    private array $gates = [];

    public function __construct(
        public readonly \DateTimeImmutable $timestamp = new \DateTimeImmutable(),
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
            foreach ($gate->actions as $actionData) {
                $actions[] = new Action(
                    type: $actionData['type'],
                    message: $actionData['message'],
                    files: $actionData['files'] ?? [],
                    priority: count($actions),
                );
            }
        }
        return $actions;
    }

    public function getPassedCount(): int
    {
        return count(array_filter($this->gates, fn(GateResult $g): bool => $g->isPass()));
    }

    public function getFailedCount(): int
    {
        return count(array_filter($this->gates, fn(GateResult $g): bool => $g->isFail()));
    }

    public function getSkippedCount(): int
    {
        return count(array_filter($this->gates, fn(GateResult $g): bool => $g->status === Status::Skip));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 'catraca/v1',
            'result' => $this->isPass() ? 'pass' : 'fail',
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'summary' => [
                'total' => count($this->gates),
                'passed' => $this->getPassedCount(),
                'failed' => $this->getFailedCount(),
                'skipped' => $this->getSkippedCount(),
            ],
            'gates' => array_map(fn(GateResult $g): array => $g->toArray(), $this->gates),
            'actions' => array_map(fn(Action $a): array => $a->toArray(), $this->getActions()),
        ];
    }
}
