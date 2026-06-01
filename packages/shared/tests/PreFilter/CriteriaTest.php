<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\PreFilter;

use DealPromoter\Shared\PreFilter\Criteria;
use PHPUnit\Framework\TestCase;

final class CriteriaTest extends TestCase
{
    public function testDefaultsMatchTheSpec(): void
    {
        $c = new Criteria();

        self::assertSame(20, $c->minDiscountPercent);
        self::assertSame(0, $c->minPriceCents);
        self::assertNull($c->maxPriceCents);
        self::assertNull($c->maxSalesRank);
        self::assertSame([], $c->allowedRootCategories);
        self::assertNull($c->minRatingStarsTimesTen);
    }

    public function testFromArrayOverridesOnlyGivenKeysAndKeepsDefaults(): void
    {
        $c = Criteria::fromArray([
            'minDiscountPercent' => 35,
            'maxPriceCents' => 50000,
            'allowedRootCategories' => [281052031, 562066],
        ]);

        self::assertSame(35, $c->minDiscountPercent);
        self::assertSame(0, $c->minPriceCents);
        self::assertSame(50000, $c->maxPriceCents);
        self::assertSame([281052031, 562066], $c->allowedRootCategories);
        self::assertNull($c->minRatingStarsTimesTen);
    }

    public function testFromArrayWithEmptyArrayEqualsDefaults(): void
    {
        $c = Criteria::fromArray([]);

        self::assertEquals(new Criteria(), $c);
    }
}
