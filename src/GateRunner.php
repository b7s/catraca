<?php

declare(strict_types=1);

namespace B7S\Catraca;

use B7S\Catraca\Enum\Status;
use Parallite\ForkExecutor;
use Parallite\ParalliteClient;
use RuntimeException;
use Throwable;

use function get_class;
use function is_array;

readonly class GateRunner
{
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
     * @return array<int, GateResult>
     */
    public function run(): array
    {
        if ($this->parallel && $this->baseline->isParallelEnabled() && ForkExecutor::isAvailable()) {
            return $this->runParallel();
        }

        return $this->runSequential();
    }

    /**
     * @return array<int, GateResult>
     */
    private function runSequential(): array
    {
        $results = [];

        foreach ($this->gates as $gateDef) {
            try {
                $results[] = $gateDef['gate']->run($this->baseline, $this->resolver);
            } catch (Throwable $e) {
                $results[] = new GateResult(
                    status: Status::Skip,
                    name: 'unknown',
                    label: $gateDef['name'],
                    message: 'Error: '.$e->getMessage(),
                    details: ['exception' => get_class($e), 'trace' => $e->getTraceAsString()],
                );
            }
        }

        return $results;
    }

    /**
     * @return array<int, GateResult>
     */
    private function runParallel(): array
    {
        $projectRoot = $this->baseline->projectRoot;
        $client = new ParalliteClient;

        $closures = [];
        foreach ($this->gates as $gateDef) {
            $gateName = $gateDef['name'];
            $gateClass = $gateDef['gate']::class;

            $closures[] = static function () use ($projectRoot, $gateName, $gateClass): array {
                try {
                    $baseline = new Baseline($projectRoot);
                    $resolver = new ToolResolver($projectRoot);
                    $gate = new $gateClass;

                    return $gate->run($baseline, $resolver)->toArray();
                } catch (Throwable $e) {
                    return [
                        'status' => 'skip',
                        'name' => 'unknown',
                        'label' => $gateName,
                        'message' => 'Error: '.$e->getMessage(),
                        'severity' => 'block',
                    ];
                }
            };
        }

        $rawResults = $client->awaitAll($closures);

        $results = [];
        foreach ($this->gates as $i => $gateDef) {
            $data = $rawResults[$i] ?? null;

            if ($data instanceof RuntimeException) {
                $results[] = new GateResult(
                    status: Status::Skip,
                    name: 'unknown',
                    label: $gateDef['name'],
                    message: 'Error: '.$data->getMessage(),
                );

                continue;
            }

            if (! is_array($data)) {
                $results[] = new GateResult(
                    status: Status::Skip,
                    name: 'unknown',
                    label: $gateDef['name'],
                    message: 'Invalid result from child process',
                );

                continue;
            }

            $results[] = GateResult::fromArray($data);
        }

        return $results;
    }
}
