<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\Status;
use B7S\Catraca\Gate\ComplexityGate;
use B7S\Catraca\Gate\CoverageGate;
use B7S\Catraca\Gate\DuplicationGate;
use B7S\Catraca\Gate\FileSizeGate;
use B7S\Catraca\Gate\SecurityGate;
use B7S\Catraca\Gate\StaticAnalysisGate;
use B7S\Catraca\Gate\StyleGate;

class Catraca
{
    private Baseline $baseline;

    private ToolResolver $resolver;

    /** @var array<int, array{gate: GateInterface, name: string}> */
    private array $gates = [];

    public function __construct(string $projectRoot)
    {
        $this->baseline = new Baseline($projectRoot);
        $this->resolver = new ToolResolver($projectRoot);

        $this->gates = [
            ['gate' => new SecurityGate, 'name' => 'Security Audit'],
            ['gate' => new StyleGate, 'name' => 'Code Style'],
            ['gate' => new StaticAnalysisGate, 'name' => 'Static Analysis'],
            ['gate' => new CoverageGate, 'name' => 'Test Coverage'],
            ['gate' => new DuplicationGate, 'name' => 'Duplication'],
            ['gate' => new FileSizeGate, 'name' => 'File Size'],
            ['gate' => new ComplexityGate, 'name' => 'Cyclomatic Complexity'],
        ];
    }

    public function init(): CheckResult
    {
        $result = new CheckResult;

        if (! $this->baseline->exists()) {
            $this->baseline->init();
        }

        foreach ($this->gates as $gateDef) {
            try {
                $gateResult = $gateDef['gate']->run($this->baseline, $this->resolver);
                $result->add($gateResult);
            } catch (\Throwable $e) {
                $result->add(new GateResult(
                    status: Status::Skip,
                    name: 'unknown',
                    label: $gateDef['name'],
                    message: 'Error: '.$e->getMessage(),
                    details: ['exception' => get_class($e), 'trace' => $e->getTraceAsString()],
                ));
            }
        }

        $this->writeBaseline($result);

        return $result;
    }

    public function check(): CheckResult
    {
        if (! $this->baseline->exists()) {
            $this->baseline->init();
        }

        $result = new CheckResult;

        foreach ($this->gates as $gateDef) {
            try {
                $gateResult = $gateDef['gate']->run($this->baseline, $this->resolver);
                $result->add($gateResult);
            } catch (\Throwable $e) {
                $result->add(new GateResult(
                    status: Status::Skip,
                    name: 'unknown',
                    label: $gateDef['name'],
                    message: 'Error: '.$e->getMessage(),
                    details: ['exception' => get_class($e), 'trace' => $e->getTraceAsString()],
                ));
            }
        }

        return $result;
    }

    private function writeBaseline(CheckResult $result): void
    {
        $data = [];
        foreach ($result->getGates() as $gate) {
            if ($gate->current !== null) {
                $data[$gate->name] = $gate->current;
            }
        }
        $this->baseline->write($data);
    }
}
