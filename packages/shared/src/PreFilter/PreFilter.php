<?php

declare(strict_types=1);

namespace DealPromoter\Shared\PreFilter;

use DealPromoter\Shared\Keepa\Candidate;

/**
 * The free, no-API-call Pre-filter over raw Keepa {@see Candidate}s.
 *
 * A Candidate becomes a *surviving candidate* iff it matches ALL {@see Criteria}
 * AND passes ALL four Outlier Guards ({@see GuardThresholds}). A rejected
 * candidate records EVERY criterion and guard that fired, not just the first.
 *
 * CRITICAL null-handling asymmetry:
 *   - Criteria are INCLUSION gates. If the data a criterion needs is null/missing,
 *     the criterion is NOT met and its reason fires. E.g. a null currentPriceCents
 *     or null avg90Cents makes both discount-below-min AND price-out-of-band fire,
 *     because we cannot show the deal clears those bars.
 *   - Guards are EXCLUSION proofs. A guard fires only when it has the data to PROVE
 *     an outlier. Null data means the guard cannot prove anything, so it passes.
 *     (Mirrors the experiment's `value != null && condition`.)
 *
 * Out of scope here (lands later): no score/ranking (P8), no deep /product?stats
 * guards (below-floor-glitch / thin-data-oos / unverifiable-drop), no Symfony
 * config wiring (P7 — the fromArray factories cover config-driven thresholds).
 */
final readonly class PreFilter
{
    public function __construct(
        private Criteria $criteria,
        private GuardThresholds $guards,
    ) {
    }

    /**
     * Partition the given candidates into survivors and rejections.
     *
     * Accepts candidates variadically: `apply($a, $b)` or `apply(...$list)`.
     */
    public function apply(Candidate ...$candidates): PreFilterResult
    {
        $survivors = [];
        $rejections = [];

        foreach ($candidates as $candidate) {
            $reasons = $this->reasonsFor($candidate);
            if ([] === $reasons) {
                $survivors[] = $candidate;
            } else {
                $rejections[] = new Rejection($candidate, $reasons);
            }
        }

        return new PreFilterResult($survivors, $rejections);
    }

    /**
     * @return list<RejectionReason>
     */
    private function reasonsFor(Candidate $candidate): array
    {
        return [
            ...$this->criteriaReasons($candidate),
            ...$this->guardReasons($candidate),
        ];
    }

    /**
     * Inclusion gates: missing data means "not met" → the reason fires.
     *
     * @return list<RejectionReason>
     */
    private function criteriaReasons(Candidate $candidate): array
    {
        $reasons = [];

        if (!$this->meetsMinDiscount($candidate)) {
            $reasons[] = RejectionReason::DiscountBelowMin;
        }
        if (!$this->withinPriceBand($candidate->currentPriceCents)) {
            $reasons[] = RejectionReason::PriceOutOfBand;
        }
        if (!$this->withinSalesRank($candidate->salesRank)) {
            $reasons[] = RejectionReason::SalesRankTooHigh;
        }
        if (!$this->inAllowedCategory($candidate->rootCategory)) {
            $reasons[] = RejectionReason::CategoryNotAllowed;
        }
        if (!$this->meetsMinRating($candidate->ratingStarsTimesTen)) {
            $reasons[] = RejectionReason::RatingBelowMin;
        }

        return $reasons;
    }

    /**
     * Exclusion proofs: a guard fires only with the data to prove an outlier;
     * null data leaves it silent.
     *
     * @return list<RejectionReason>
     */
    private function guardReasons(Candidate $candidate): array
    {
        $reasons = [];

        $avg30 = $candidate->avg30Cents;
        $avg90 = $candidate->avg90Cents;
        if (null !== $avg30 && null !== $avg90 && $avg90 > $this->guards->spikeRatio * $avg30) {
            $reasons[] = RejectionReason::SpikePollutedBaseline;
        }

        $rankDrops = $candidate->salesRankDrops90;
        if (null !== $rankDrops && $rankDrops < $this->guards->minSalesRankDrops90) {
            $reasons[] = RejectionReason::NoDemand;
        }

        $current = $candidate->currentPriceCents;
        if (null !== $current && $current < $this->guards->absPriceFloorCents) {
            $reasons[] = RejectionReason::AbsPriceFloor;
        }

        $claimed = $candidate->dropPercent90;
        if (null !== $claimed && $claimed > $this->guards->maxClaimedDropPercent) {
            $reasons[] = RejectionReason::AbsurdClaim;
        }

        return $reasons;
    }

    /**
     * Verified drop = (avg90 - current) / avg90, as a percentage, must be ≥ the
     * minimum. Compared by cross-multiplication so the boundary is exact (no
     * float rounding). Null current or null/non-positive avg90 cannot clear the
     * bar, so the criterion is not met.
     */
    private function meetsMinDiscount(Candidate $candidate): bool
    {
        $current = $candidate->currentPriceCents;
        $avg90 = $candidate->avg90Cents;
        if (null === $current || null === $avg90 || $avg90 <= 0) {
            return false;
        }

        // (avg90 - current) / avg90 * 100 >= min   ⇔   (avg90 - current) * 100 >= min * avg90
        return ($avg90 - $current) * 100 >= $this->criteria->minDiscountPercent * $avg90;
    }

    private function withinPriceBand(?int $current): bool
    {
        if (null === $current) {
            return false;
        }
        if ($current < $this->criteria->minPriceCents) {
            return false;
        }
        $max = $this->criteria->maxPriceCents;

        return null === $max || $current <= $max;
    }

    private function withinSalesRank(?int $salesRank): bool
    {
        $max = $this->criteria->maxSalesRank;
        if (null === $max) {
            return true;
        }

        return null !== $salesRank && $salesRank <= $max;
    }

    private function inAllowedCategory(int $rootCategory): bool
    {
        $allowed = $this->criteria->allowedRootCategories;

        return [] === $allowed || \in_array($rootCategory, $allowed, true);
    }

    private function meetsMinRating(?int $rating): bool
    {
        $min = $this->criteria->minRatingStarsTimesTen;
        if (null === $min) {
            return true;
        }

        return null !== $rating && $rating >= $min;
    }
}
