<?php

declare(strict_types=1);

namespace B7S\Catraca;

use B7S\Catraca\Gate\ComplexityGate;
use B7S\Catraca\Gate\CoverageGate;
use B7S\Catraca\Gate\DuplicationGate;
use B7S\Catraca\Gate\FileSizeGate;
use B7S\Catraca\Gate\PerformanceGate;
use B7S\Catraca\Gate\SecurityGate;
use B7S\Catraca\Gate\StaticAnalysisGate;
use B7S\Catraca\Gate\StyleGate;

class Catraca
{
    private Baseline $baseline;

    private GateRunner $gateRunner;

    public function __construct(
        string $projectRoot,
        string $profile = 'default',
        ?string $changedFrom = null,
        ?int $timeoutOverride = null,
    ) {
        $this->baseline = new Baseline(
            $projectRoot,
            profile: $profile,
            changedFrom: $changedFrom,
            timeoutOverride: $timeoutOverride,
        );
        $resolver = new ToolResolver($projectRoot);

        $gates = [
            ['gate' => new SecurityGate(), 'name' => 'Security Audit'],
            ['gate' => new StyleGate(), 'name' => 'Code Style'],
            ['gate' => new StaticAnalysisGate(), 'name' => 'Static Analysis'],
            ['gate' => new CoverageGate(), 'name' => 'Test Coverage'],
            ['gate' => new DuplicationGate(), 'name' => 'Duplication'],
            ['gate' => new FileSizeGate(), 'name' => 'File Size'],
            ['gate' => new ComplexityGate(), 'name' => 'Cyclomatic Complexity'],
            ['gate' => new PerformanceGate(), 'name' => 'Performance'],
        ];

        $this->gateRunner = new GateRunner($this->baseline, $resolver, $gates);
    }

    /**
     * @return array<int, string>
     */
    public function getGateLabels(): array
    {
        return $this->gateRunner->getLabels();
    }

    public function cancel(): void
    {
        $this->gateRunner->cancel();
    }

    public function init(?GateRunObserverInterface $observer = null): CheckResult
    {
        $start = hrtime(true);
        $result = new CheckResult();

        $this->baseline->init();
        $this->runGates($result, $observer);
        $this->writeBaseline($result);

        $result->setMetrics((int) (hrtime(true) - $start), memory_get_peak_usage(true));

        return $result;
    }

    public function check(?GateRunObserverInterface $observer = null): CheckResult
    {
        $this->baseline->init();

        $start = hrtime(true);
        $result = new CheckResult();
        $this->runGates($result, $observer);

        $result->setMetrics((int) (hrtime(true) - $start), memory_get_peak_usage(true));

        return $result;
    }

    private function writeBaseline(CheckResult $result): void
    {
        $results = [];

        foreach ($result->getGates() as $gate) {
            if ($gate->current !== null) {
                $results[$gate->name] = $gate->current;
            }
        }

        $this->baseline->updateResults($results);
    }

    private function runGates(CheckResult $result, ?GateRunObserverInterface $observer): void
    {
        foreach ($this->gateRunner->run($observer) as $gateResult) {
            $result->add($gateResult);
        }
    }
}
