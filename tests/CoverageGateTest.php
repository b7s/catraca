<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\Gate\CoverageGate;
use B7S\Catraca\GateResult;
use B7S\Catraca\GatePolicyEvaluator;
use B7S\Catraca\ToolResolver;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class CoverageGateTest extends TestCase
{
    private string $tmpDir;

    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-coverage-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->baselinePath = $this->tmpDir . '/catraca_baseline.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->baselinePath)) {
            unlink($this->baselinePath);
        }

        rmdir($this->tmpDir);
    }

    public function test_skip_mode_short_circuits_without_invoking_a_test_runner(): void
    {
        $this->writeBaseline(['mode' => 'skip', 'floor' => 85.0]);

        $baseline = new Baseline($this->tmpDir);
        $resolver = new ToolResolver($this->tmpDir);
        $result = (new CoverageGate())->run($baseline, $resolver);

        self::assertSame(Status::Skip, $result->status);
        self::assertSame('coverage', $result->name);
        self::assertStringContainsString('mode: skip', $result->message);
    }

    public function test_informational_mode_does_not_short_circuit_run(): void
    {
        // Without a real test runner present in $tmpDir, the gate reports
        // "no test runner found" rather than the skip message — proving
        // `informational` mode still attempts to execute the runner.
        $this->writeBaseline(['mode' => 'informational', 'floor' => 85.0]);

        $baseline = new Baseline($this->tmpDir);
        $resolver = new ToolResolver($this->tmpDir);
        $result = (new CoverageGate())->run($baseline, $resolver);

        self::assertSame(Status::Skip, $result->status);
        self::assertStringContainsString('No test runner found', $result->message);
    }

    public function test_evaluator_returns_skip_status_when_coverage_mode_is_skip(): void
    {
        // Even if a real GateResult reaches the evaluator with a measured
        // percentage (e.g. when the gate was forced via a different tool),
        // the evaluator honours `mode: skip` and downgrades any verdict
        // to Status::Skip before assertion-counted metrics are consulted.
        $this->writeBaseline(['mode' => 'skip', 'floor' => 85.0]);

        $baseline = new Baseline($this->tmpDir);
        $result = (new GatePolicyEvaluator())->evaluate(
            new GateResult(
                status: Status::Pass,
                name: 'coverage',
                label: 'Test Coverage',
                message: '50.00%',
                baseline: ['percentage' => 85.0],
                current: ['percentage' => 50.0],
            ),
            $baseline,
        );

        self::assertSame(Status::Skip, $result->status);
    }

    public function test_evaluator_falls_back_to_no_regression_when_mode_is_unknown(): void
    {
        // Defensive: a typo in the baseline ('skipped' instead of 'skip')
        // must not silently turn coverage off — it falls back to the
        // no_regression check so a regression still surfaces.
        $this->writeBaseline(['mode' => 'skipped', 'floor' => 85.0]);

        $baseline = new Baseline($this->tmpDir);
        $result = (new GatePolicyEvaluator())->evaluate(
            new GateResult(
                status: Status::Pass,
                name: 'coverage',
                label: 'Test Coverage',
                message: '50.00%',
                baseline: ['percentage' => 85.0],
                current: ['percentage' => 50.0],
            ),
            $baseline,
        );

        self::assertSame(Status::Fail, $result->status);
    }

    /**
     * @param array<string, mixed> $coverageConfig
     */
    private function writeBaseline(array $coverageConfig): void
    {
        $data = [
            'schema' => Baseline::SCHEMA,
            'config' => [
                'coverage' => $coverageConfig,
                'complexity' => ['mode' => 'no_regression', 'block_at' => 50, 'warn_at' => 20],
                'file_size' => ['mode' => 'no_regression', 'max_lines' => 1000],
                'style' => ['mode' => 'no_regression'],
                'static_analysis' => ['mode' => 'no_regression'],
                'duplication' => ['mode' => 'no_regression', 'max_percentage' => 0.0, 'min_lines' => 3, 'min_tokens' => 30],
                'performance' => ['mode' => 'no_regression', 'rules' => [], 'fixers' => []],
                'security' => ['mode' => 'no_regression', 'rules' => [], 'fixers' => [], 'released_days' => 3],
            ],
            'results' => [
                'coverage' => ['percentage' => 85.0],
                'complexity' => ['max_ccn' => 0, 'violations' => 0, 'warnings' => 0],
                'file_size' => ['over_limit' => 0],
                'style' => ['violations' => 0],
                'static_analysis' => ['errors' => 0],
                'duplication' => ['percentage' => 0.0, 'clones' => 0],
                'performance' => ['violations' => 0],
                'security' => ['advisories' => 0, 'findings' => 0, 'critical' => 0],
            ],
        ];
        file_put_contents($this->baselinePath, json_encode($data, JSON_PRETTY_PRINT) ?: '');
    }
}
