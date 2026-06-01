<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\PreFilter;

use DealPromoter\Shared\PreFilter\GuardThresholds;
use PHPUnit\Framework\TestCase;

final class GuardThresholdsTest extends TestCase
{
    public function testDefaultsMatchTheExperimentConstants(): void
    {
        $g = new GuardThresholds();

        self::assertSame(3.0, $g->spikeRatio);
        self::assertSame(1, $g->minSalesRankDrops90);
        self::assertSame(200, $g->absPriceFloorCents);
        self::assertSame(97, $g->maxClaimedDropPercent);
    }

    public function testFromArrayOverridesOnlyGivenKeysAndKeepsDefaults(): void
    {
        $g = GuardThresholds::fromArray([
            'spikeRatio' => 2.5,
            'absPriceFloorCents' => 500,
        ]);

        self::assertSame(2.5, $g->spikeRatio);
        self::assertSame(1, $g->minSalesRankDrops90);
        self::assertSame(500, $g->absPriceFloorCents);
        self::assertSame(97, $g->maxClaimedDropPercent);
    }

    public function testFromArrayAcceptsIntForSpikeRatio(): void
    {
        $g = GuardThresholds::fromArray(['spikeRatio' => 4]);

        self::assertSame(4.0, $g->spikeRatio);
    }

    public function testFromArrayWithEmptyArrayEqualsDefaults(): void
    {
        self::assertEquals(new GuardThresholds(), GuardThresholds::fromArray([]));
    }
}
