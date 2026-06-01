<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\AlreadyPosted;

use DealPromoter\Shared\AlreadyPosted\AlreadyPostedGuard;
use DealPromoter\Shared\AlreadyPosted\NeverRepostPolicy;
use DealPromoter\Shared\AlreadyPosted\RepostPolicy;
use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Storage\RecordedPriceHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AlreadyPostedGuard} using an in-memory fake
 * {@see RecordedPriceHistory}. No I/O, no Symfony container.
 */
final class AlreadyPostedGuardTest extends TestCase
{
    // ---- Helpers -----------------------------------------------------------

    /**
     * Build a minimal Candidate with the given ASIN (other fields irrelevant
     * to the guard).
     */
    private function candidate(string $asin): Candidate
    {
        return new Candidate(
            asin: $asin,
            title: 'Test Product',
            imageUrl: '',
            currentPriceCents: 5000,
            avg30Cents: 9000,
            avg90Cents: 10000,
            dropPercent90: 50,
            salesRankDrops90: 5,
            salesRank: 10000,
            ratingStarsTimesTen: 45,
            rootCategory: 281052031,
            categories: [281052031],
        );
    }

    /**
     * In-memory fake: consulted set is seeded at construction time.
     *
     * @param list<string> $postedAsins
     */
    private function fakeHistory(array $postedAsins = []): RecordedPriceHistory
    {
        return new class($postedAsins) implements RecordedPriceHistory {
            /** @param list<string> $posted */
            public function __construct(private readonly array $posted)
            {
            }

            public function hasPostedAsin(string $asin): bool
            {
                return \in_array($asin, $this->posted, true);
            }
        };
    }

    private function guardWithHistory(RecordedPriceHistory $history): AlreadyPostedGuard
    {
        return new AlreadyPostedGuard($history, new NeverRepostPolicy());
    }

    // ---- NeverRepostPolicy -------------------------------------------------

    public function testNeverRepostPolicyAlwaysReturnsFalse(): void
    {
        $policy = new NeverRepostPolicy();

        self::assertFalse($policy->allowsRepost('B0ANYTHING1'));
        self::assertFalse($policy->allowsRepost('B0ANYTHING2'));
    }

    // ---- Empty history: everything passes through --------------------------

    public function testEmptyHistoryPassesEveryCandidate(): void
    {
        $guard = $this->guardWithHistory($this->fakeHistory());
        $a = $this->candidate('B0000000AA');
        $b = $this->candidate('B0000000BB');

        $result = $guard->apply($a, $b);

        self::assertCount(2, $result->survivors);
        self::assertCount(0, $result->suppressed);
    }

    public function testEmptyHistorySurvivorOrderIsPreserved(): void
    {
        $guard = $this->guardWithHistory($this->fakeHistory());
        $a = $this->candidate('B0000000AA');
        $b = $this->candidate('B0000000BB');
        $c = $this->candidate('B0000000CC');

        $result = $guard->apply($a, $b, $c);

        self::assertSame('B0000000AA', $result->survivors[0]->asin);
        self::assertSame('B0000000BB', $result->survivors[1]->asin);
        self::assertSame('B0000000CC', $result->survivors[2]->asin);
    }

    // ---- Seeded ASIN is suppressed -----------------------------------------

    public function testSeededAsinIsSuppressed(): void
    {
        $guard = $this->guardWithHistory($this->fakeHistory(['B0POSTED001']));
        $candidate = $this->candidate('B0POSTED001');

        $result = $guard->apply($candidate);

        self::assertCount(0, $result->survivors);
        self::assertCount(1, $result->suppressed);
        self::assertSame('B0POSTED001', $result->suppressed[0]->asin);
    }

    // ---- Mixed batch: only posted ASINs are suppressed ---------------------

    public function testMixedBatchSuppressesOnlyPostedAsins(): void
    {
        $guard = $this->guardWithHistory($this->fakeHistory(['B0POSTED001', 'B0POSTED002']));
        $fresh1 = $this->candidate('B0FRESH0001');
        $posted1 = $this->candidate('B0POSTED001');
        $fresh2 = $this->candidate('B0FRESH0002');
        $posted2 = $this->candidate('B0POSTED002');

        $result = $guard->apply($fresh1, $posted1, $fresh2, $posted2);

        self::assertCount(2, $result->survivors);
        self::assertCount(2, $result->suppressed);
        self::assertSame('B0FRESH0001', $result->survivors[0]->asin);
        self::assertSame('B0FRESH0002', $result->survivors[1]->asin);
        self::assertSame('B0POSTED001', $result->suppressed[0]->asin);
        self::assertSame('B0POSTED002', $result->suppressed[1]->asin);
    }

    public function testSurvivorOrderIsPreservedInMixedBatch(): void
    {
        $guard = $this->guardWithHistory($this->fakeHistory(['B0POSTED001']));
        $a = $this->candidate('B0FRESH0003');
        $b = $this->candidate('B0POSTED001');
        $c = $this->candidate('B0FRESH0004');

        $result = $guard->apply($a, $b, $c);

        self::assertSame('B0FRESH0003', $result->survivors[0]->asin);
        self::assertSame('B0FRESH0004', $result->survivors[1]->asin);
    }

    // ---- RepostPolicy hook is consulted ------------------------------------

    public function testAllowRepostPolicyLetsPostedAsinThrough(): void
    {
        // An "always allow repost" stub proves the policy hook is consulted.
        $alwaysAllow = new class implements RepostPolicy {
            public function allowsRepost(string $asin): bool
            {
                return true;
            }
        };

        $guard = new AlreadyPostedGuard($this->fakeHistory(['B0POSTED001']), $alwaysAllow);
        $candidate = $this->candidate('B0POSTED001');

        $result = $guard->apply($candidate);

        // Even though the ASIN is posted, the policy allows a repost.
        self::assertCount(1, $result->survivors);
        self::assertCount(0, $result->suppressed);
    }

    public function testNeverRepostPolicyWithPostedAsinSuppresses(): void
    {
        $guard = new AlreadyPostedGuard($this->fakeHistory(['B0POSTED001']), new NeverRepostPolicy());
        $candidate = $this->candidate('B0POSTED001');

        $result = $guard->apply($candidate);

        self::assertCount(0, $result->survivors);
        self::assertCount(1, $result->suppressed);
    }
}
