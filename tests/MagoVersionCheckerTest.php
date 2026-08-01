<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\MagoVersionChecker;
use PHPUnit\Framework\TestCase;

final class MagoVersionCheckerTest extends TestCase
{
    public function test_accepts_the_minimum_and_newer_versions(): void
    {
        self::assertTrue(MagoVersionChecker::satisfies('1.45.0', '1.45.0'));
        self::assertTrue(MagoVersionChecker::satisfies('1.46.0', '1.45.0'));
        self::assertTrue(MagoVersionChecker::satisfies('2.0.0', '1.45.0'));
    }

    public function test_rejects_old_or_invalid_versions(): void
    {
        self::assertFalse(MagoVersionChecker::satisfies('1.44.9', '1.45.0'));
        self::assertFalse(MagoVersionChecker::satisfies('latest', '1.45.0'));
    }
}
