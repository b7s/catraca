<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\GateRunner;
use B7S\Catraca\ToolResolver;
use Parallite\ForkExecutor;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class GateRunnerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-runner-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $path = $this->tmpDir . '/catraca_baseline.json';
        if (file_exists($path)) {
            unlink($path);
        }

        rmdir($this->tmpDir);
    }

    public function test_parallel_gate_workers_disable_nested_parallelism(): void
    {
        if (!ForkExecutor::isAvailable()) {
            self::markTestSkipped('pcntl is not available');
        }

        $baseline = new Baseline($this->tmpDir);
        $baseline->init();
        $runner = new GateRunner($baseline, new ToolResolver($this->tmpDir), [[
            'gate' => new ParallelStateGate(),
            'name' => 'Parallel state',
        ]]);

        $results = $runner->run();

        self::assertCount(1, $results);
        self::assertSame(['parallel_enabled' => false], $results[0]->current);
    }

    public function test_empty_gate_list_returns_without_starting_workers(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->init();
        $runner = new GateRunner($baseline, new ToolResolver($this->tmpDir), []);

        self::assertSame([], $runner->run());
    }
}

final class ParallelStateGate implements GateInterface
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        return new GateResult(
            status: Status::Pass,
            name: 'parallel_state',
            label: 'Parallel state',
            message: 'Parallel state captured',
            current: ['parallel_enabled' => $baseline->isParallelEnabled()],
        );
    }
}
