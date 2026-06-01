<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\PreFilter;

use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\PreFilter\Criteria;
use DealPromoter\Shared\PreFilter\GuardThresholds;
use DealPromoter\Shared\PreFilter\PreFilter;
use DealPromoter\Shared\PreFilter\RejectionReason;
use PHPUnit\Framework\TestCase;

final class PreFilterTest extends TestCase
{
    /**
     * A Candidate that, under default config, passes every criterion and guard.
     * Verified drop = (10000 - 5000) / 10000 = 50% >= 20%.
     */
    private function passingCandidate(
        ?int $currentPriceCents = 5000,
        ?int $avg30Cents = 9000,
        ?int $avg90Cents = 10000,
        ?int $dropPercent90 = 50,
        ?int $salesRankDrops90 = 5,
        ?int $salesRank = 10000,
        ?int $ratingStarsTimesTen = 45,
        int $rootCategory = 281052031,
    ): Candidate {
        return new Candidate(
            asin: 'B00PASSING0',
            title: 'A fine deal',
            imageUrl: '',
            currentPriceCents: $currentPriceCents,
            avg30Cents: $avg30Cents,
            avg90Cents: $avg90Cents,
            dropPercent90: $dropPercent90,
            salesRankDrops90: $salesRankDrops90,
            salesRank: $salesRank,
            ratingStarsTimesTen: $ratingStarsTimesTen,
            rootCategory: $rootCategory,
            categories: [$rootCategory],
        );
    }

    /**
     * @return list<RejectionReason>
     */
    private function reasonsFor(Candidate $candidate, Criteria $criteria, GuardThresholds $guards): array
    {
        $result = (new PreFilter($criteria, $guards))->apply($candidate);
        if ([] === $result->rejections) {
            return [];
        }

        return $result->rejections[0]->reasons;
    }

    public function testAFullyPassingCandidateSurvivesWithNoRejection(): void
    {
        $result = (new PreFilter(new Criteria(), new GuardThresholds()))->apply($this->passingCandidate());

        self::assertCount(1, $result->survivors);
        self::assertSame([], $result->rejections);
        self::assertSame('B00PASSING0', $result->survivors[0]->asin);
    }

    // ---- Criterion: discount ------------------------------------------------

    public function testDiscountExactlyAtThresholdIsMet(): void
    {
        // (10000 - 8000) / 10000 = 20% == min 20.
        $c = $this->passingCandidate(currentPriceCents: 8000, avg90Cents: 10000);

        self::assertNotContains(RejectionReason::DiscountBelowMin, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    public function testDiscountJustBelowThresholdFires(): void
    {
        // (10000 - 8001) / 10000 = 19.99% < 20.
        $c = $this->passingCandidate(currentPriceCents: 8001, avg90Cents: 10000);

        self::assertContains(RejectionReason::DiscountBelowMin, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Criterion: price band ----------------------------------------------

    public function testPriceAtLowerBandEdgeIsMet(): void
    {
        $criteria = Criteria::fromArray(['minPriceCents' => 5000, 'maxPriceCents' => 9000]);
        $c = $this->passingCandidate(currentPriceCents: 5000, avg90Cents: 20000);

        self::assertNotContains(RejectionReason::PriceOutOfBand, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testPriceAtUpperBandEdgeIsMet(): void
    {
        $criteria = Criteria::fromArray(['minPriceCents' => 5000, 'maxPriceCents' => 9000]);
        $c = $this->passingCandidate(currentPriceCents: 9000, avg90Cents: 20000);

        self::assertNotContains(RejectionReason::PriceOutOfBand, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testPriceBelowBandFires(): void
    {
        $criteria = Criteria::fromArray(['minPriceCents' => 5000]);
        $c = $this->passingCandidate(currentPriceCents: 4999, avg90Cents: 20000);

        self::assertContains(RejectionReason::PriceOutOfBand, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testPriceAboveBandFires(): void
    {
        $criteria = Criteria::fromArray(['maxPriceCents' => 9000]);
        $c = $this->passingCandidate(currentPriceCents: 9001, avg90Cents: 20000);

        self::assertContains(RejectionReason::PriceOutOfBand, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testNullMaxPriceMeansNoUpperCap(): void
    {
        $c = $this->passingCandidate(currentPriceCents: 999999, avg90Cents: 9000000);

        self::assertNotContains(RejectionReason::PriceOutOfBand, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Criterion: sales rank ----------------------------------------------

    public function testSalesRankAtMaxIsMet(): void
    {
        $criteria = Criteria::fromArray(['maxSalesRank' => 50000]);
        $c = $this->passingCandidate(salesRank: 50000);

        self::assertNotContains(RejectionReason::SalesRankTooHigh, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testSalesRankOverMaxFires(): void
    {
        $criteria = Criteria::fromArray(['maxSalesRank' => 50000]);
        $c = $this->passingCandidate(salesRank: 50001);

        self::assertContains(RejectionReason::SalesRankTooHigh, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testNullMaxSalesRankDisablesTheCheck(): void
    {
        $c = $this->passingCandidate(salesRank: 9999999);

        self::assertNotContains(RejectionReason::SalesRankTooHigh, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Criterion: category ------------------------------------------------

    public function testCategoryInNonEmptyAllowlistIsMet(): void
    {
        $criteria = Criteria::fromArray(['allowedRootCategories' => [281052031, 562066]]);
        $c = $this->passingCandidate(rootCategory: 562066);

        self::assertNotContains(RejectionReason::CategoryNotAllowed, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testCategoryOutsideNonEmptyAllowlistFires(): void
    {
        $criteria = Criteria::fromArray(['allowedRootCategories' => [281052031, 562066]]);
        $c = $this->passingCandidate(rootCategory: 999);

        self::assertContains(RejectionReason::CategoryNotAllowed, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testEmptyAllowlistAllowsAnyCategory(): void
    {
        $c = $this->passingCandidate(rootCategory: 999);

        self::assertNotContains(RejectionReason::CategoryNotAllowed, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Criterion: rating --------------------------------------------------

    public function testRatingAtMinimumIsMet(): void
    {
        $criteria = Criteria::fromArray(['minRatingStarsTimesTen' => 40]);
        $c = $this->passingCandidate(ratingStarsTimesTen: 40);

        self::assertNotContains(RejectionReason::RatingBelowMin, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testRatingBelowMinimumFires(): void
    {
        $criteria = Criteria::fromArray(['minRatingStarsTimesTen' => 40]);
        $c = $this->passingCandidate(ratingStarsTimesTen: 39);

        self::assertContains(RejectionReason::RatingBelowMin, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    public function testNullMinRatingDisablesTheCheck(): void
    {
        $c = $this->passingCandidate(ratingStarsTimesTen: 0);

        self::assertNotContains(RejectionReason::RatingBelowMin, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    public function testNullRatingFailsAnActiveRatingCriterion(): void
    {
        $criteria = Criteria::fromArray(['minRatingStarsTimesTen' => 40]);
        $c = $this->passingCandidate(ratingStarsTimesTen: null);

        self::assertContains(RejectionReason::RatingBelowMin, $this->reasonsFor($c, $criteria, new GuardThresholds()));
    }

    // ---- Null-data: criteria fire, guards do NOT ----------------------------

    public function testNullCurrentPriceFiresDiscountAndPriceButNoGuards(): void
    {
        $c = $this->passingCandidate(currentPriceCents: null);

        $reasons = $this->reasonsFor($c, new Criteria(), new GuardThresholds());

        self::assertContains(RejectionReason::DiscountBelowMin, $reasons);
        self::assertContains(RejectionReason::PriceOutOfBand, $reasons);
        // Guards that need current price cannot prove an outlier on null.
        self::assertNotContains(RejectionReason::AbsPriceFloor, $reasons);
    }

    public function testNullAvg90FiresDiscountCriterionButNotSpikeGuard(): void
    {
        // avg30 huge, avg90 null: spike guard cannot fire without avg90.
        $c = $this->passingCandidate(avg30Cents: 100, avg90Cents: null);

        $reasons = $this->reasonsFor($c, new Criteria(), new GuardThresholds());

        self::assertContains(RejectionReason::DiscountBelowMin, $reasons);
        self::assertNotContains(RejectionReason::SpikePollutedBaseline, $reasons);
    }

    public function testNullGuardDataLeavesGuardsSilent(): void
    {
        // Everything guards need is null; only the criteria they overlap with fire.
        $c = $this->passingCandidate(
            avg30Cents: null,
            salesRankDrops90: null,
            dropPercent90: null,
        );

        $reasons = $this->reasonsFor($c, new Criteria(), new GuardThresholds());

        self::assertNotContains(RejectionReason::SpikePollutedBaseline, $reasons);
        self::assertNotContains(RejectionReason::NoDemand, $reasons);
        self::assertNotContains(RejectionReason::AbsurdClaim, $reasons);
    }

    // ---- Guard: spike-polluted-baseline -------------------------------------

    public function testSpikeAtExactlyThreeXDoesNotFire(): void
    {
        // avg90 == 3 * avg30 is not strictly greater.
        $c = $this->passingCandidate(avg30Cents: 3000, avg90Cents: 9000, currentPriceCents: 1000);

        self::assertNotContains(RejectionReason::SpikePollutedBaseline, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    public function testSpikeAboveThreeXFires(): void
    {
        $c = $this->passingCandidate(avg30Cents: 3000, avg90Cents: 9001, currentPriceCents: 1000);

        self::assertContains(RejectionReason::SpikePollutedBaseline, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Guard: no-demand ---------------------------------------------------

    public function testNoDemandFiresAtZeroRankDrops(): void
    {
        $c = $this->passingCandidate(salesRankDrops90: 0);

        self::assertContains(RejectionReason::NoDemand, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    public function testNoDemandPassesAtOneRankDrop(): void
    {
        $c = $this->passingCandidate(salesRankDrops90: 1);

        self::assertNotContains(RejectionReason::NoDemand, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Guard: abs-price-floor ---------------------------------------------

    public function testAbsPriceFloorFiresAt199(): void
    {
        // Use a big avg90 so the discount criterion stays satisfied.
        $c = $this->passingCandidate(currentPriceCents: 199, avg90Cents: 100000);

        self::assertContains(RejectionReason::AbsPriceFloor, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    public function testAbsPriceFloorPassesAt200(): void
    {
        $c = $this->passingCandidate(currentPriceCents: 200, avg90Cents: 100000);

        self::assertNotContains(RejectionReason::AbsPriceFloor, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Guard: absurd-claim ------------------------------------------------

    public function testAbsurdClaimPassesAt97(): void
    {
        $c = $this->passingCandidate(dropPercent90: 97);

        self::assertNotContains(RejectionReason::AbsurdClaim, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    public function testAbsurdClaimFiresAt98(): void
    {
        $c = $this->passingCandidate(dropPercent90: 98);

        self::assertContains(RejectionReason::AbsurdClaim, $this->reasonsFor($c, new Criteria(), new GuardThresholds()));
    }

    // ---- Multiple reasons on one candidate ----------------------------------

    public function testOneCandidateCanRecordMultipleReasons(): void
    {
        // Tiny price (abs floor + below min price), weak discount, dead demand.
        $c = $this->passingCandidate(
            currentPriceCents: 100,
            avg30Cents: 110,
            avg90Cents: 110,
            dropPercent90: 9,
            salesRankDrops90: 0,
            salesRank: 9999999,
        );
        $criteria = Criteria::fromArray(['minPriceCents' => 1000, 'maxSalesRank' => 100000]);

        $reasons = $this->reasonsFor($c, $criteria, new GuardThresholds());

        self::assertContains(RejectionReason::DiscountBelowMin, $reasons);
        self::assertContains(RejectionReason::PriceOutOfBand, $reasons);
        self::assertContains(RejectionReason::SalesRankTooHigh, $reasons);
        self::assertContains(RejectionReason::AbsPriceFloor, $reasons);
        self::assertContains(RejectionReason::NoDemand, $reasons);
    }

    // ---- Result aggregation -------------------------------------------------

    public function testApplyPartitionsSurvivorsAndRejectionsAndCountsReasons(): void
    {
        $survivor = $this->passingCandidate();
        $rejected = $this->passingCandidate(salesRankDrops90: 0); // no-demand

        $result = (new PreFilter(new Criteria(), new GuardThresholds()))->apply($survivor, $rejected);

        self::assertCount(1, $result->survivors);
        self::assertCount(1, $result->rejections);
        self::assertSame(['no-demand' => 1], $result->reasonCounts());
    }

    public function testEveryRejectionHasAtLeastOneReason(): void
    {
        $result = (new PreFilter(new Criteria(), new GuardThresholds()))->apply(
            $this->passingCandidate(salesRankDrops90: 0),
        );

        foreach ($result->rejections as $rejection) {
            self::assertNotEmpty($rejection->reasons);
        }
    }

    // ---- Config drives the result, no code change ---------------------------

    public function testDifferentConfigYieldsDifferentSurvivorSetOverSameCandidates(): void
    {
        // This candidate survives a lenient discount but not a strict one.
        // Verified drop = (10000 - 7000) / 10000 = 30%.
        $candidates = [$this->passingCandidate(currentPriceCents: 7000, avg90Cents: 10000)];

        $lenient = new PreFilter(Criteria::fromArray(['minDiscountPercent' => 20]), new GuardThresholds());
        $strict = new PreFilter(Criteria::fromArray(['minDiscountPercent' => 40]), new GuardThresholds());

        self::assertCount(1, $lenient->apply(...$candidates)->survivors);
        self::assertCount(0, $strict->apply(...$candidates)->survivors);
    }
}
