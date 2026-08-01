<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Baseline;
use B7S\Catraca\CheckResult;
use B7S\Catraca\RunHistoryStore;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function json_decode;
use function mkdir;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class RunHistoryStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-history-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        (new Baseline($this->tmpDir))->init();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/.catraca/runs/*.json') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->tmpDir . '/.catraca/runs');
        @rmdir($this->tmpDir . '/.catraca');
        @unlink($this->tmpDir . '/catraca_baseline.json');
        rmdir($this->tmpDir);
    }

    public function test_history_contains_profile_and_configuration_hash(): void
    {
        $path = (new RunHistoryStore())->write(new CheckResult(), $this->tmpDir, 'default', 1);
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('default', $data['profile']);
        self::assertSame(64, strlen($data['config_hash']));
        self::assertCount(1, glob($this->tmpDir . '/.catraca/runs/*.json') ?: []);
    }
}
