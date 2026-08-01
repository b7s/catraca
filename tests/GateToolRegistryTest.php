<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Baseline;
use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\ToolResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function chmod;
use function file_exists;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class GateToolRegistryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-tool-registry-' . uniqid('', true);
        mkdir($this->tmpDir . '/vendor/bin', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['catraca_baseline.json', 'vendor/bin/mago', 'vendor/bin/pint'] as $file) {
            if (file_exists($this->tmpDir . '/' . $file)) {
                unlink($this->tmpDir . '/' . $file);
            }
        }

        rmdir($this->tmpDir . '/vendor/bin');
        rmdir($this->tmpDir . '/vendor');
        rmdir($this->tmpDir);
    }

    public function test_auto_uses_the_central_fallback_order(): void
    {
        file_put_contents($this->tmpDir . '/vendor/bin/pint', "#!/bin/sh\nexit 0\n");
        chmod($this->tmpDir . '/vendor/bin/pint', 0755);
        $baseline = new Baseline($this->tmpDir);
        $baseline->init();

        $tool = GateToolRegistry::resolve($baseline, new ToolResolver($this->tmpDir), 'style');

        self::assertNotNull($tool);
        self::assertSame('pint', $tool->name);
        self::assertSame('mago -> pint -> php-cs-fixer', GateToolRegistry::description('style'));
    }

    public function test_invalid_tool_lists_every_valid_choice(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->write([
            'config' => ['tools' => ['format' => 'prettier']],
            'results' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tool "prettier" for style. Use one of: auto, mago, pint, php-cs-fixer.');

        GateToolRegistry::candidates($baseline, 'style');
    }

    public function test_auto_falls_back_when_mago_is_below_the_minimum_version(): void
    {
        $this->writeTool('mago', "#!/bin/sh\necho 'mago 1.44.9'\n");
        $this->writeTool('pint', "#!/bin/sh\nexit 0\n");
        $baseline = new Baseline($this->tmpDir);
        $baseline->init();

        $tool = GateToolRegistry::resolve($baseline, new ToolResolver($this->tmpDir), 'style');

        self::assertNotNull($tool);
        self::assertSame('pint', $tool->name);
    }

    public function test_explicit_old_mago_reports_the_required_minimum_version(): void
    {
        $this->writeTool('mago', "#!/bin/sh\necho 'mago 1.44.9'\n");
        $baseline = new Baseline($this->tmpDir);
        $baseline->write([
            'config' => [
                'tools' => [
                    'format' => 'mago',
                    'options' => ['mago' => ['minimum_version' => '1.45.0']],
                ],
            ],
            'results' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mago 1.44.9 does not satisfy the required minimum version 1.45.0.');

        GateToolRegistry::resolve($baseline, new ToolResolver($this->tmpDir), 'style');
    }

    private function writeTool(string $name, string $contents): void
    {
        $path = $this->tmpDir . '/vendor/bin/' . $name;
        file_put_contents($path, $contents);
        chmod($path, 0755);
    }
}
