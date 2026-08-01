<?php

declare(strict_types=1);

namespace B7S\Catraca;

use B7S\Catraca\Enum\Status;
use Parallite\ForkExecutor;
use Throwable;

use function array_keys;
use function array_map;
use function count;
use function get_class;
use function is_array;
use function min;

class GateRunner
{
    private ?ParallelTaskRunner $activeRunner = null;

    private bool $cancelled = false;

    /**
     * @param  array<int, array{gate: GateInterface, name: string}>  $gates
     */
    public function __construct(
        private Baseline $baseline,
        private ToolResolver $resolver,
        private array $gates,
        private bool $parallel = true,
    ) {}

    /**
     * @return array<int, string>
     */
    public function getLabels(): array
    {
        return array_map(static fn(array $gate): string => $gate['name'], $this->gates);
    }

    /**
     * @return array<int, GateResult>
     */
    public function run(?GateRunObserverInterface $observer = null): array
    {
        if ($this->parallel && $this->baseline->isParallelEnabled() && ForkExecutor::isAvailable()) {
            return $this->runParallel($observer);
        }

        return $this->runSequential($observer);
    }

    /**
     * @return array<int, GateResult>
     */
    private function runSequential(?GateRunObserverInterface $observer): array
    {
        $results = [];

        foreach ($this->gates as $index => $gateDef) {
            $observer?->started($index);
            if ($this->cancelled) {
                $gateResult = $this->cancelledResult($gateDef['name']);
                $results[] = $gateResult;
                $observer?->finished($index, $gateResult);

                continue;
            }

            $observer?->tick();

            try {
                $gateResult = $gateDef['gate']->run($this->baseline, $this->resolver);
            } catch (Throwable $exception) {
                $gateResult = $this->errorResult($gateDef['name'], $exception);
            }
            $gateResult = (new GatePolicyEvaluator())->evaluate($gateResult, $this->baseline);

            $results[] = $gateResult;
            $observer?->finished($index, $gateResult);
        }

        return $results;
    }

    /**
     * Uses a bounded rolling worker pool. Completed gates are reported
     * immediately and the next queued gate starts as soon as a slot is free.
     *
     * @return array<int, GateResult>
     */
    private function runParallel(?GateRunObserverInterface $observer): array
    {
        $projectRoot = $this->baseline->projectRoot;
        $closures = [];

        $profile = $this->baseline->getProfile();
        $changedFrom = $this->baseline->getChangedFrom();
        foreach ($this->gates as $gateDef) {
            $gateName = $gateDef['name'];
            $gateClass = $gateDef['gate']::class;

            $closures[] = static function () use ($projectRoot, $gateName, $gateClass, $profile, $changedFrom): array {
                try {
                    // The gate is already a worker, so child gates must not
                    // recursively create another worker pool.
                    $baseline = new Baseline(
                        $projectRoot,
                        parallelOverride: false,
                        profile: $profile,
                        changedFrom: $changedFrom,
                    );
                    $resolver = new ToolResolver($projectRoot);
                    $gate = new $gateClass();

                    return $gate->run($baseline, $resolver)->toArray();
                } catch (Throwable $exception) {
                    return self::errorData($gateName, $exception);
                }
            };
        }

        if ($closures === []) {
            return [];
        }

        $this->activeRunner = new ParallelTaskRunner(min($this->baseline->getMaxProcesses(), count($closures)));

        $rawResults = $this->activeRunner->run(
            $closures,
            $observer === null ? null : static fn(int $index) => $observer->started($index),
            $observer === null ? null : static fn() => $observer->tick(),
            $observer === null
                ? null
                : function (int $index, mixed $data) use ($observer): void {
                    $observer->finished($index, $this->hydrateResult($index, $data));
                },
        );
        $this->activeRunner = null;

        return array_map(
            fn(mixed $data, int $index): GateResult => $this->hydrateResult($index, $data),
            $rawResults,
            array_keys($rawResults),
        );
    }

    private function hydrateResult(int $index, mixed $data): GateResult
    {
        $gateDef = $this->gates[$index];

        if ($data instanceof CancelledException) {
            return $this->cancelledResult($gateDef['name']);
        }
        if ($data instanceof Throwable) {
            return $this->errorResult($gateDef['name'], $data);
        }

        if (!is_array($data)) {
            return new GateResult(
                status: Status::Skip,
                name: 'unknown',
                label: $gateDef['name'],
                message: 'Invalid result from child process',
            );
        }

        return (new GatePolicyEvaluator())->evaluate(GateResult::fromArray($data), $this->baseline);
    }

    private function errorResult(string $label, Throwable $exception): GateResult
    {
        return new GateResult(
            status: Status::Fail,
            name: 'unknown',
            label: $label,
            message: 'Error: ' . $exception->getMessage(),
            details: [
                'exception' => get_class($exception),
                'trace' => $exception->getTraceAsString(),
            ],
        );
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->activeRunner?->cancel();
    }

    private function cancelledResult(string $label): GateResult
    {
        return new GateResult(
            status: Status::Cancelled,
            name: 'cancelled',
            label: $label,
            message: 'Cancelled by signal',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorData(string $label, Throwable $exception): array
    {
        return [
            'status' => 'fail',
            'name' => 'unknown',
            'label' => $label,
            'message' => 'Error: ' . $exception->getMessage(),
            'severity' => 'block',
            'details' => [
                'exception' => get_class($exception),
                'trace' => $exception->getTraceAsString(),
            ],
        ];
    }
}
