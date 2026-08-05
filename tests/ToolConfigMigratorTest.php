<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\ToolConfigMigrator;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_exists;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class ToolConfigMigratorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-migrator-' . uniqid('', true);
        mkdir($this->tmpDir . '/vendor/bin', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            'vendor/bin/mago',
            'vendor/bin/pint',
            'vendor/bin/phpstan',
            'vendor/bin/psalm',
            'vendor/bin/pest',
            'vendor/bin/phpunit',
            'vendor/bin/php-cs-fixer',
        ] as $file) {
            if (file_exists($this->tmpDir . '/' . $file)) {
                unlink($this->tmpDir . '/' . $file);
            }
        }

        rmdir($this->tmpDir . '/vendor/bin');
        rmdir($this->tmpDir . '/vendor');
        rmdir($this->tmpDir);
    }

    public function test_v1_auto_tool_is_migrated_to_an_installed_project_package(): void
    {
        $this->writeTool('pint');
        $this->writeTool('phpstan');
        $this->writeTool('php-cs-fixer');
        $this->writeTool('phpunit');

        $data = $this->v1Baseline(['tool' => 'auto']);

        $migrated = ToolConfigMigrator::migrate($data, $this->tmpDir);

        $tools = $migrated['config']['tools'];
        self::assertSame('pint', $tools['format']);
        self::assertSame('phpstan', $tools['analyze']);
        self::assertSame('phpunit', $tools['coverage']);
        self::assertSame('php-cs-fixer', $tools['lint']);
    }

    public function test_v1_without_installed_tools_keeps_mago_as_default(): void
    {
        $data = $this->v1Baseline(['tool' => 'auto']);

        $migrated = ToolConfigMigrator::migrate($data, $this->tmpDir);

        $tools = $migrated['config']['tools'];
        self::assertSame(GateToolRegistry::DEFAULT, $tools['format']);
        self::assertSame(GateToolRegistry::DEFAULT, $tools['analyze']);
        self::assertSame(GateToolRegistry::DEFAULT, $tools['coverage']);
        self::assertSame(GateToolRegistry::DEFAULT, $tools['lint']);
    }

    public function test_v1_explicit_legacy_tool_is_preserved(): void
    {
        $this->writeTool('pint');
        $this->writeTool('phpstan');

        $data = $this->v1Baseline(['tool' => 'mago']);

        $migrated = ToolConfigMigrator::migrate($data, $this->tmpDir);

        $tools = $migrated['config']['tools'];
        self::assertSame('mago', $tools['format']);
        self::assertSame('mago', $tools['analyze']);
    }

    public function test_v1_missing_tool_key_is_migrated_like_auto(): void
    {
        $this->writeTool('pint');

        $data = $this->v1Baseline(null);

        $migrated = ToolConfigMigrator::migrate($data, $this->tmpDir);

        self::assertSame('pint', $migrated['config']['tools']['format']);
    }

    public function test_v2_baseline_with_auto_is_not_overridden_by_detection(): void
    {
        $this->writeTool('pint');
        $this->writeTool('phpstan');

        $data = [
            'schema' => 'catraca/v2',
            'config' => [
                'tools' => [
                    'format' => 'auto',
                    'analyze' => 'auto',
                    'coverage' => 'auto',
                    'lint' => 'auto',
                ],
                'style' => ['mode' => 'no_regression'],
                'static_analysis' => ['mode' => 'no_regression'],
            ],
            'results' => [],
        ];

        $migrated = ToolConfigMigrator::migrate($data, $this->tmpDir);

        $tools = $migrated['config']['tools'];
        self::assertSame('auto', $tools['format']);
        self::assertSame('auto', $tools['analyze']);
    }

    public function test_migration_without_project_root_keeps_auto(): void
    {
        $this->writeTool('pint');

        $data = $this->v1Baseline(['tool' => 'auto']);

        $migrated = ToolConfigMigrator::migrate($data);

        self::assertSame('auto', $migrated['config']['tools']['format']);
    }

    /**
     * @param  array<string, mixed>|null  $tool
     * @return array<string, mixed>
     */
    private function v1Baseline(?array $tool): array
    {
        $gateTool = static fn(string $gate): array => (
            $tool === null
                ? ['mode' => 'no_regression']
                : ['tool' => $tool['tool'] ?? 'auto', 'mode' => 'no_regression']
        );

        return [
            'config' => [
                'style' => $gateTool('style'),
                'static_analysis' => $gateTool('static_analysis'),
                'coverage' => $gateTool('coverage'),
                'performance' => $gateTool('performance'),
                'mago' => [
                    'enabled' => true,
                    'format' => true,
                    'analyze' => true,
                    'lint' => true,
                    'version' => '1.45.0',
                ],
            ],
            'results' => [],
        ];
    }

    private function writeTool(string $name): void
    {
        $path = $this->tmpDir . '/vendor/bin/' . $name;
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0755);
    }
}
