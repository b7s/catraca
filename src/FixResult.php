<?php

namespace B7S\Catraca;

use B7S\Catraca\Fixer\FixerResult;
use DateTimeImmutable;
use DateTimeInterface;

use function array_map;
use function count;

class FixResult
{
    /** @var array<int, FixerResult> */
    private array $fixers = [];

    public function __construct(
        public readonly DateTimeImmutable $timestamp = new DateTimeImmutable,
    ) {}

    public function add(FixerResult $result): void
    {
        $this->fixers[] = $result;
    }

    /** @return array<int, FixerResult> */
    public function getFixers(): array
    {
        return $this->fixers;
    }

    public function getFixedCount(): int
    {
        return count(array_filter($this->fixers, static fn (FixerResult $f): bool => $f->fixed));
    }

    public function getSkippedCount(): int
    {
        return count(array_filter($this->fixers, static fn (FixerResult $f): bool => $f->skipped));
    }

    public function getErrorCount(): int
    {
        return count(array_filter($this->fixers, static fn (FixerResult $f): bool => ! $f->fixed && ! $f->skipped && $f->message !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 'catraca/v1',
            'type' => 'fix',
            'timestamp' => $this->timestamp->format(DateTimeInterface::ATOM),
            'summary' => [
                'total' => count($this->fixers),
                'fixed' => $this->getFixedCount(),
                'skipped' => $this->getSkippedCount(),
                'errors' => $this->getErrorCount(),
            ],
            'fixers' => array_map(static fn (FixerResult $f): array => [
                'label' => $f->label,
                'fixed' => $f->fixed,
                'skipped' => $f->skipped,
                'message' => $f->message,
            ], $this->fixers),
        ];
    }
}
