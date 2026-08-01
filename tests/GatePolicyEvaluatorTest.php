<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GatePolicyEvaluator;
use B7S\Catraca\GateResult;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class GatePolicyEvaluatorTest extends TestCase
{
    private string $tmpDir;

    private Baseline $baseline;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-policy-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->baseline = new Baseline($this->tmpDir);
        $this->baseline->init();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->baseline->getPath())) {
            unlink($this->baseline->getPath());
        }
        rmdir($this->tmpDir);
    }

    public function test_no_regression_passes_an_unchanged_metric_even_if_gate_is_absolute_failure(): void
    {
        $result = $this->evaluate(Status::Fail, 0, 0);

        self::assertSame(Status::Pass, $result->status);
    }

    public function test_no_regression_fails_when_metric_increases(): void
    {
        $result = $this->evaluate(Status::Pass, 0, 1);

        self::assertSame(Status::Fail, $result->status);
    }

    public function test_unavailable_metric_warns_by_default(): void
    {
        $result = (new GatePolicyEvaluator())->evaluate(
            new GateResult(
                status: Status::Pass,
                name: 'coverage',
                label: 'Coverage',
                message: 'Unavailable',
                baseline: ['percentage' => 85.0],
                current: ['percentage' => null],
            ),
            $this->baseline,
        );

        self::assertSame(Status::Warn, $result->status);
    }

    private function evaluate(Status $status, int $baseline, int $current): GateResult
    {
        return (new GatePolicyEvaluator())->evaluate(
            new GateResult(
                status: $status,
                name: 'style',
                label: 'Style',
                message: 'Style result',
                baseline: ['violations' => $baseline],
                current: ['violations' => $current],
            ),
            $this->baseline,
        );
    }
}
