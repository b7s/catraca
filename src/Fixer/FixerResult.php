<?php

namespace B7S\Catraca\Fixer;

readonly class FixerResult
{
    public function __construct(
        public string $label,
        public bool $fixed = false,
        public bool $skipped = false,
        public string $message = '',
    ) {}

    public function isSuccess(): bool
    {
        return $this->fixed && !$this->skipped;
    }
}
