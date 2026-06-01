<?php

declare(strict_types=1);

namespace B7S\Catraca;

use B7S\Catraca\Enum\Status;
use Throwable;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function get_class;
use function is_array;
use function json_decode;
use function json_encode;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

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
        if ($this->parallel && $this->baseline->isParallelEnabled() && $this->pcntlAvailable()) {
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
        $tempFiles = [];
        $pids = [];

        foreach ($this->gates as $i => $gateDef) {
            $tempFiles[$i] = tempnam(sys_get_temp_dir(), 'catraca_'.posix_getpid().'_'.$i.'_');

            $pid = pcntl_fork();

            if ($pid === -1) {
                $tempFiles[$i] = null;

                continue;
            }

            if ($pid === 0) {
                $this->childWorker($projectRoot, $gateDef, $tempFiles[$i]);
            }

            $pids[$i] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        foreach ($this->gates as $i => $gateDef) {
            $tempPath = $tempFiles[$i] ?? null;

            if ($tempPath === null || ! file_exists($tempPath)) {
                $results[] = new GateResult(
                    status: Status::Skip,
                    name: 'unknown',
                    label: $gateDef['name'],
                    message: 'Fork failed or no result file',
                );

                continue;
            }

            $raw = file_get_contents($tempPath);
            @unlink($tempPath);

            if ($raw === false || $raw === '') {
                $results[] = new GateResult(
                    status: Status::Skip,
                    name: 'unknown',
                    label: $gateDef['name'],
                    message: 'Empty result from child process',
                );

                continue;
            }

            $data = json_decode($raw, true);
            $results[] = is_array($data) ? GateResult::fromArray($data) : new GateResult(
                status: Status::Skip,
                name: 'unknown',
                label: $gateDef['name'],
                message: 'Invalid result from child process',
            );
        }

        return $results;
    }

    private function childWorker(string $projectRoot, array $gateDef, string $tempPath): never
    {
        try {
            $baseline = new Baseline($projectRoot);
            $resolver = new ToolResolver($projectRoot);
            $gateClass = $gateDef['gate']::class;
            $gate = new $gateClass;

            $result = $gate->run($baseline, $resolver)->toArray();
            file_put_contents($tempPath, json_encode($result, JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            file_put_contents($tempPath, json_encode([
                'status' => 'skip',
                'name' => 'unknown',
                'label' => $gateDef['name'],
                'message' => 'Error: '.$e->getMessage(),
                'severity' => 'block',
            ], JSON_UNESCAPED_SLASHES));
        }

        exit(0);
    }

    private function pcntlAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_getpid');
    }
}
