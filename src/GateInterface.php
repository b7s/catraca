<?php

namespace B7S\Catraca;

interface GateInterface
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult;
}
