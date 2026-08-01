<?php

declare(strict_types=1);

namespace B7S\Catraca;

use function array_map;
use function count;

readonly class MagoRunResult
{
    /**
     * @param  array<int, array{file: string, line: int, message: string, code: string, level: string}>  $issues
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
        public array $issues = [],
    ) {}

    public function issueCount(): int
    {
        return count($this->issues);
    }

    /** @return array<int, string> */
    public function files(): array
    {
        return array_map(static fn(array $issue): string => $issue['file'] . ':' . $issue['line'], $this->issues);
    }
}
