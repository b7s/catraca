<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\MagoResultParser;
use PHPUnit\Framework\TestCase;

use function json_encode;

final class MagoResultParserTest extends TestCase
{
    public function test_parses_expanded_mago_json_and_ignores_log_prefix(): void
    {
        $output =
            " INFO loading workspace\n"
            . json_encode([
                'issues' => [[
                    'level' => 'warning',
                    'code' => 'strict-comparison',
                    'message' => 'Use a strict comparison.',
                    'annotations' => [[
                        'kind' => 'primary',
                        'span' => [
                            'file_id' => ['name' => 'src/Example.php', 'path' => '/project/src/Example.php'],
                            'start' => ['offset' => 42, 'line' => 7],
                            'end' => ['offset' => 45, 'line' => 7],
                        ],
                    ]],
                ]],
            ]);

        self::assertSame(
            [[
                'file' => 'src/Example.php',
                'line' => 7,
                'message' => 'Use a strict comparison.',
                'code' => 'strict-comparison',
                'level' => 'warning',
            ]],
            MagoResultParser::parse($output),
        );
    }

    public function test_accepts_empty_report_and_rejects_invalid_json(): void
    {
        self::assertSame([], MagoResultParser::parse(''));
        self::assertSame([], MagoResultParser::parse('{"issues":[]}'));
        self::assertNull(MagoResultParser::parse('not-json'));
    }
}
