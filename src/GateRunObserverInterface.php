<?php

declare(strict_types=1);

namespace B7S\Catraca;

interface GateRunObserverInterface
{
    public function started(int $index): void;

    public function tick(): void;

    public function finished(int $index, GateResult $result): void;
}
