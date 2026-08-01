<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\Gate\StaticAnalysisGate;
use B7S\Catraca\Gate\StyleGate;
use B7S\Catraca\MagoRunner;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class MagoIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-mago-test-' . uniqid('', true);
        mkdir($this->tmpDir . '/vendor/bin', 0755, true);
        mkdir($this->tmpDir . '/src', 0755, true);
        file_put_contents($this->tmpDir . '/src/Example.php', "<?php\n");
        file_put_contents($this->tmpDir . '/vendor/bin/mago', <<<'PHP'
            #!/usr/bin/env php

            <?php

            if (in_array('--version', $argv, true)) {
                echo "mago 1.46.0\n";
                exit(0);
            }

            $command = in_array('format', $argv, true)
                ? 'format'
                : (in_array('analyze', $argv, true) ? 'analyze' : 'lint');
            file_put_contents(__DIR__.'/calls.log', implode(' ', $argv).PHP_EOL, FILE_APPEND);

            if ($command === 'analyze') {
                echo json_encode([
                    'issues' => [[
                        'level' => 'error',
                        'code' => 'undefined-function',
                        'message' => 'Call to an undefined function.',
                        'annotations' => [[
                            'kind' => 'primary',
                            'span' => [
                                'file_id' => ['name' => 'src/Example.php', 'path' => null, 'size' => 6, 'file_type' => 'host'],
                                'start' => ['offset' => 0, 'line' => 1],
                                'end' => ['offset' => 5, 'line' => 1],
                            ],
                        ]],
                    ]],
                ]);
                exit(1);
            }

            if ($command === 'lint') {
                echo '{"issues":[]}';
            }

            exit(0);
            PHP);
        chmod($this->tmpDir . '/vendor/bin/mago', 0755);
    }

    protected function tearDown(): void
    {
        foreach ([
            '/catraca_baseline.json',
            '/src/Example.php',
            '/vendor/bin/calls.log',
            '/vendor/bin/mago',
        ] as $path) {
            @unlink($this->tmpDir . $path);
        }

        rmdir($this->tmpDir . '/src');
        rmdir($this->tmpDir . '/vendor/bin');
        rmdir($this->tmpDir . '/vendor');
        rmdir($this->tmpDir);
    }

    public function test_mago_is_preferred_for_style_and_static_analysis(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->init();
        $resolver = new ToolResolver($this->tmpDir);

        $style = (new StyleGate())->run($baseline, $resolver);
        $analysis = (new StaticAnalysisGate())->run($baseline, $resolver);

        self::assertSame(Status::Pass, $style->status);
        self::assertTrue(str_contains($style->message, 'via Mago'));
        self::assertSame(Status::Fail, $analysis->status);
        self::assertSame(['errors' => 1], $analysis->current);
        self::assertSame('src/Example.php', $analysis->details['errors'][0]['file']);
    }

    public function test_mago_commands_receive_workspace_thread_and_json_options(): void
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->init();
        $mago = (new ToolResolver($this->tmpDir))->resolveOrFail('mago');

        $result = (new MagoRunner())->diagnostics(
            $mago,
            'lint',
            (new SourcePathResolver())->resolveForBaseline($baseline),
            $baseline,
        );

        $calls = (string) file_get_contents($this->tmpDir . '/vendor/bin/calls.log');
        self::assertSame(0, $result->issueCount());
        self::assertTrue(str_contains($calls, '--workspace ' . $this->tmpDir));
        self::assertTrue(str_contains($calls, '--threads 1'));
        self::assertTrue(str_contains($calls, '--reporting-format json'));
        self::assertTrue(str_contains($calls, '--minimum-report-level error'));
        self::assertTrue(str_contains(
            $calls,
            '--only instanceof-stringable,no-redundant-yield-from,no-sprintf-concat,prefer-static-closure',
        ));
    }
}
