<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\Creators;

use DealPromoter\Shared\Creators\LiveSnapshot;
use PHPUnit\Framework\TestCase;

final class LiveSnapshotTest extends TestCase
{
    /**
     * @return LiveSnapshot a baseline price-valid snapshot with NO attestation;
     *                      callers override the two attestation signals
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

    public function testDealDetailsAloneCountsAsAttestation(): void
    {
        self::assertTrue($this->snapshot(true, null)->hasAmazonAttestation());
    }

    public function testWasPriceBasisAloneCountsAsAttestation(): void
    {
        self::assertTrue($this->snapshot(false, 'WAS_PRICE')->hasAmazonAttestation());
    }

    public function testListPriceBasisIsNotAttestation(): void
    {
        // Seller-set MSRP is gameable, so a LIST_PRICE basis is not attestation.
        self::assertFalse($this->snapshot(false, 'LIST_PRICE')->hasAmazonAttestation());
    }

    public function testNeitherSignalIsNotAttestation(): void
    {
        self::assertFalse($this->snapshot(false, null)->hasAmazonAttestation());
    }
}
