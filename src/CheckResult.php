<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\Status;
use DateTimeImmutable;
use DateTimeInterface;

use function count;
use function intdiv;
use function is_array;
use function is_string;
use function sprintf;

class CheckResult
{
    /** @var array<int, GateResult> */
    private array $gates = [];

    private ?int $elapsedNs = null;

    private ?int $peakMemory = null;

    public function __construct(
        public readonly DateTimeImmutable $timestamp = new DateTimeImmutable(),
    ) {}

    public function add(GateResult $gate): void
    {
        $this->gates[] = $gate;
    }

    /**
     * Records run-level metrics for display in the output. Called once after
     * all gates have executed.
     *
     * @param  int  $elapsedNanoseconds  Wall-clock time from hrtime(true)
     * @param  int  $peakMemoryBytes     Peak memory via memory_get_peak_usage(true)
     */
    public function setMetrics(int $elapsedNanoseconds, int $peakMemoryBytes): void
    {
        $this->elapsedNs = $elapsedNanoseconds;
        $this->peakMemory = $peakMemoryBytes;
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

    public function getTime(): ?string
    {
        return $this->elapsedNs !== null ? self::formatTime($this->elapsedNs) : null;
    }

    public function getMemory(): ?string
    {
        return $this->peakMemory !== null ? self::formatMemory($this->peakMemory) : null;
    }

    /** @return array{
     *     schema: string,
     *     type: string,
     *     result: string,
     *     timestamp: string,
     *     summary: array{total: int, passed: int, failed: int, skipped: int},
     *     gates: array<int, array<string, mixed>>,
     *     actions: array<int, array{type: string, priority: int, message: string, files: array<int, string>, reasons: array<int, string>}>,
     *     time: ?string,
     *     memory: ?string
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
            'time' => $this->elapsedNs !== null ? self::formatTime($this->elapsedNs) : null,
            'memory' => $this->peakMemory !== null ? self::formatMemory($this->peakMemory) : null,
        ];
    }

    /** Formats nanoseconds as hh:mm:ss.μμμμμμ (microsecond precision). */
    private static function formatTime(int $nanoseconds): string
    {
        $totalMicroseconds = intdiv($nanoseconds, 1_000);
        $microseconds = $totalMicroseconds % 1_000_000;
        $totalSeconds = intdiv($totalMicroseconds, 1_000_000);
        $seconds = $totalSeconds % 60;
        $totalMinutes = intdiv($totalSeconds, 60);
        $minutes = $totalMinutes % 60;
        $hours = intdiv($totalMinutes, 60);

        return sprintf('%02d:%02d:%02d.%06d', $hours, $minutes, $seconds, $microseconds);
    }

    /** Formats bytes as a human-readable value (B, KB, MB, GB, TB, PB). */
    private static function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024.0 && $i < (count($units) - 1)) {
            $value /= 1024.0;
            $i++;
        }

        $formatted = $i === 0 ? (string) (int) $value : sprintf('%.2f', $value);

        return $formatted . ' ' . $units[$i];
    }
}
