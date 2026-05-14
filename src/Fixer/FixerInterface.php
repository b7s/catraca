<?php

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Baseline;
use B7S\Catraca\ToolResolver;

interface FixerInterface
{
    /**
     * Human-readable label for this fixer.
     */
    public function getLabel(): string;

    /**
     * Run the fixer and return a result describing what was done.
     */
    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult;
}
