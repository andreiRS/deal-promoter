<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\Creators;

use DealPromoter\Shared\Creators\LiveSnapshot;
use PHPUnit\Framework\TestCase;

final class LiveSnapshotTest extends TestCase
{
    /**
     * @return LiveSnapshot a baseline price-valid snapshot with NO verification;
     *                      callers override the two verification signals
     */
    private function snapshot(bool $hasDealDetails, ?string $savingBasisType): LiveSnapshot
    {
        return new LiveSnapshot(
            asin: 'B000000000',
            priceCents: 1999,
            availability: 'IN_STOCK',
            condition: 'New',
            merchantId: 'AMZN-DE',
            savingsPercent: null,
            savingBasisType: $savingBasisType,
            hasDealDetails: $hasDealDetails,
            violatesMap: null,
            detailPageUrl: 'https://www.amazon.de/dp/B000000000?tag=t-21&linkCode=ogi',
        );
    }

    public function testDealDetailsAloneCountsAsVerified(): void
    {
        self::assertTrue($this->snapshot(true, null)->isAmazonVerified());
    }

    public function testWasPriceBasisAloneCountsAsVerified(): void
    {
        self::assertTrue($this->snapshot(false, 'WAS_PRICE')->isAmazonVerified());
    }

    public function testListPriceBasisIsNotVerified(): void
    {
        // Seller-set MSRP is gameable, so a LIST_PRICE basis is not Amazon-verified.
        self::assertFalse($this->snapshot(false, 'LIST_PRICE')->isAmazonVerified());
    }

    public function testNeitherSignalIsNotVerified(): void
    {
        self::assertFalse($this->snapshot(false, null)->isAmazonVerified());
    }
}
