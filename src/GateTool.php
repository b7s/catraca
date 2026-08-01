<?php

declare(strict_types=1);

namespace B7S\Catraca;

readonly class GateTool
{
    public function __construct(
        public string $name,
        public string $path,
    ) {}
}
