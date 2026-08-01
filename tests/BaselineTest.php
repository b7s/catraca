<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Baseline;
use B7S\Catraca\GateToolRegistry;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

final class BaselineTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-baseline-test-' . uniqid('', true);
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

    public function test_initializes_config_and_results_in_separate_groups(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->init();

        $data = $baseline->read();

        self::assertIsArray($data);
        self::assertSame('catraca/v2', $data['schema']);
        self::assertSame(3, $data['config']['duplication']['min_lines']);
        self::assertSame(1200.0, $baseline->getGateTimeout('coverage'));
        self::assertSame('1.45.0', $data['config']['tools']['options']['mago']['minimum_version']);
        self::assertSame('error', $data['config']['tools']['options']['mago']['minimum_report_level']);
        self::assertArrayNotHasKey('mago', $data['config']);
        self::assertSame('auto', $baseline->getGateTool('style'));
        self::assertSame(['mago', 'pint', 'php-cs-fixer'], GateToolRegistry::candidates($baseline, 'style'));
        self::assertSame(1, $baseline->getMagoThreads());
        self::assertSame(0.0, $data['results']['duplication']['percentage']);
        self::assertArrayNotHasKey('percentage', $data['config']['duplication']);
        self::assertArrayNotHasKey('min_lines', $data['results']['duplication']);
    }

    public function test_migrates_v1_without_losing_custom_configuration(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $legacy = [
            'schema' => 'catraca/v1',
            'duplication' => [
                'percentage' => 4.5,
                'clones' => 7,
                'min_lines' => 8,
                'custom_option' => 'retained',
            ],
            'parallel' => ['enabled' => false],
        ];
        file_put_contents($baseline->getPath(), json_encode($legacy, JSON_THROW_ON_ERROR));

        $baseline->init();

        $data = $this->readJson($baseline->getPath());
        self::assertSame('catraca/v2', $data['schema']);
        self::assertSame(4.5, $data['results']['duplication']['percentage']);
        self::assertSame(7, $data['results']['duplication']['clones']);
        self::assertSame(8, $data['config']['duplication']['min_lines']);
        self::assertSame('retained', $data['config']['duplication']['custom_option']);
        self::assertFalse($data['config']['parallel']['enabled']);
        self::assertSame(4, $data['config']['parallel']['max_processes']);
    }

    public function test_result_update_preserves_configuration_and_other_results(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->write([
            'config' => [
                'duplication' => ['min_lines' => 11, 'min_tokens' => 40],
                'parallel' => ['enabled' => true, 'max_processes' => 2],
            ],
            'results' => [
                'duplication' => ['percentage' => 3.0, 'clones' => 2],
                'style' => ['violations' => 9],
            ],
        ]);

        $baseline->updateResults([
            'duplication' => ['percentage' => 1.5, 'clones' => 1],
        ]);

        $data = $this->readJson($baseline->getPath());
        self::assertSame(11, $data['config']['duplication']['min_lines']);
        self::assertSame(2, $data['config']['parallel']['max_processes']);
        self::assertSame(1.5, $data['results']['duplication']['percentage']);
        self::assertSame(9, $data['results']['style']['violations']);
    }

    public function test_init_does_not_overwrite_results_from_a_newer_writer(): void
    {
        $writer = new Baseline($this->tmpDir);
        $writer->write([
            'config' => ['parallel' => ['enabled' => true]],
            'results' => ['style' => ['violations' => 0]],
        ]);

        $staleReader = new Baseline($this->tmpDir);
        $staleReader->read();

        $writer->updateResults([
            'style' => ['violations' => 3],
        ]);
        $staleReader->init();

        $data = $this->readJson($writer->getPath());
        self::assertSame(3, $data['results']['style']['violations']);
        self::assertSame(4, $data['config']['parallel']['max_processes']);
    }

    public function test_parallel_configuration_is_validated_and_can_be_overridden(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->write([
            'config' => ['parallel' => ['enabled' => true, 'max_processes' => 200]],
            'results' => [],
        ]);

        self::assertTrue($baseline->isParallelEnabled());
        self::assertSame(128, $baseline->getMaxProcesses());
        self::assertFalse((new Baseline($this->tmpDir, parallelOverride: false))->isParallelEnabled());
    }

    public function test_named_profile_keeps_results_isolated(): void
    {
        $default = new Baseline($this->tmpDir);
        $default->init();

        $profile = new Baseline($this->tmpDir, profile: 'api');
        $profile->updateResults([
            'coverage' => ['percentage' => 72.5],
        ]);

        $data = $this->readJson($default->getPath());
        self::assertSame(85, $data['results']['coverage']['percentage']);
        self::assertSame(72.5, $data['profiles']['api']['results']['coverage']['percentage']);
        self::assertSame(72.5, $profile->getResult('coverage', 'percentage'));
    }

    public function test_migrates_legacy_gate_tools_to_generic_operations(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->write([
            'config' => [
                'style' => ['tool' => 'Pint', 'mode' => 'no_regression'],
                'static_analysis' => ['tool' => 'Psalm'],
                'mago' => [
                    'version' => '1.45.0',
                    'threads' => 2,
                    'minimum_report_level' => 'warning',
                ],
            ],
            'results' => [],
        ]);

        $baseline->init();

        $data = $this->readJson($baseline->getPath());
        self::assertIsArray($data['config']);
        $config = $data['config'];
        self::assertIsArray($config['tools']);
        $tools = $config['tools'];
        self::assertSame('pint', $tools['format']);
        self::assertSame('psalm', $tools['analyze']);
        self::assertSame('1.45.0', $tools['options']['mago']['minimum_version']);
        self::assertSame(2, $tools['options']['mago']['threads']);
        self::assertSame('warning', $tools['options']['mago']['minimum_report_level']);
        self::assertArrayNotHasKey('mago', $config);
        self::assertArrayNotHasKey('tool', $config['style']);
        self::assertArrayNotHasKey('tool', $config['static_analysis']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        self::assertTrue(is_array($data));

        return $data;
    }
}
