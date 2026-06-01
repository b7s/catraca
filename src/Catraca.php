<?php

namespace B7S\Catraca;

use B7S\Catraca\Gate\ComplexityGate;
use B7S\Catraca\Gate\CoverageGate;
use B7S\Catraca\Gate\DuplicationGate;
use B7S\Catraca\Gate\FileSizeGate;
use B7S\Catraca\Gate\PerformanceGate;
use B7S\Catraca\Gate\SecurityGate;
use B7S\Catraca\Gate\StaticAnalysisGate;
use B7S\Catraca\Gate\StyleGate;

use function is_array;

class Catraca
{
    private Baseline $baseline;

    private GateRunner $gateRunner;

    public function __construct(string $projectRoot)
    {
        $this->baseline = new Baseline($projectRoot);
        $resolver = new ToolResolver($projectRoot);

        $gates = [
            ['gate' => new SecurityGate, 'name' => 'Security Audit'],
            ['gate' => new StyleGate, 'name' => 'Code Style'],
            ['gate' => new StaticAnalysisGate, 'name' => 'Static Analysis'],
            ['gate' => new CoverageGate, 'name' => 'Test Coverage'],
            ['gate' => new DuplicationGate, 'name' => 'Duplication'],
            ['gate' => new FileSizeGate, 'name' => 'File Size'],
            ['gate' => new ComplexityGate, 'name' => 'Cyclomatic Complexity'],
            ['gate' => new PerformanceGate, 'name' => 'Performance'],
        ];

        $this->gateRunner = new GateRunner($this->baseline, $resolver, $gates);
    }

    public function init(): CheckResult
    {
        $result = new CheckResult;

        $this->baseline->init();

        $this->runGates($result);

        $this->writeBaseline($result);

        return $result;
    }

    public function check(): CheckResult
    {
        $this->baseline->init();

        $result = new CheckResult;

        $this->runGates($result);

        return $result;
    }

    private function writeBaseline(CheckResult $result): void
    {
        $existing = $this->baseline->read() ?? [];
        $data = $existing;

        foreach ($result->getGates() as $gate) {
            if ($gate->current !== null) {
                $data[$gate->name] = array_merge(
                    is_array($existing[$gate->name] ?? null) ? $existing[$gate->name] : [],
                    $gate->current,
                );
            }
        }

        $this->baseline->write($data);
    }

    private function runGates(CheckResult $result): void
    {
        $gateResults = $this->gateRunner->run();

        foreach ($gateResults as $gateResult) {
            $result->add($gateResult);
        }
    }
}
