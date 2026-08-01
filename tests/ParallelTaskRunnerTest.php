<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\ParallelTaskRunner;
use Parallite\ForkExecutor;
use PHPUnit\Framework\TestCase;

final class ParallelTaskRunnerTest extends TestCase
{
    public function test_reports_worker_lifecycle_events_and_preserves_result_order(): void
    {
        if (!ForkExecutor::isAvailable()) {
            self::markTestSkipped('pcntl is not available');
        }

        $started = [];
        $finished = [];
        $ticks = 0;
        $runner = new ParallelTaskRunner(2);

        $results = $runner->run(
            [
                static fn(): string => 'first',
                static fn(): string => 'second',
                static fn(): string => 'third',
            ],
            static function (int $index) use (&$started): void {
                $started[] = $index;
            },
            static function () use (&$ticks): void {
                $ticks++;
            },
            static function (int $index) use (&$finished): void {
                $finished[] = $index;
            },
        );

        self::assertSame(['first', 'second', 'third'], $results);
        self::assertSame([0, 1, 2], $started);
        self::assertEqualsCanonicalizing([0, 1, 2], $finished);
        self::assertGreaterThan(0, $ticks);
    }
}
