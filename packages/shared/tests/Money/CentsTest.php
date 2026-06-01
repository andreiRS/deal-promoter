<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\Money;

use DealPromoter\Shared\Money\Cents;
use PHPUnit\Framework\TestCase;

final class CentsTest extends TestCase
{
    public function testTreatsValuesWithinToleranceAsEqual(): void
    {
        // Keepa avg* and stats.* are the same value rounded differently; compare
        // within a cent, never with ===.
        self::assertTrue(Cents::equalWithinTolerance(91892, 91893));
        self::assertTrue(Cents::equalWithinTolerance(91892, 91892));
    }

    public function testTreatsValuesBeyondToleranceAsDifferent(): void
    {
        self::assertFalse(Cents::equalWithinTolerance(699, 720));
    }

    public function testToleranceIsConfigurable(): void
    {
        self::assertTrue(Cents::equalWithinTolerance(699, 720, 25));
        self::assertFalse(Cents::equalWithinTolerance(699, 720, 20));
    }
}
