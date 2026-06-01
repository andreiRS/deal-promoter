<?php

declare(strict_types=1);

namespace DealPromoter\Shared\PreFilter;

/**
 * Every way a Candidate can be dropped by the free, no-API Pre-filter.
 *
 * The first five are Criteria misses (positive inclusion gates the deal failed
 * to clear); the last four are Outlier Guards (proofs the deal is junk). The
 * backing strings are stable identifiers used in diagnostics and stored records,
 * so do not rename them without a migration.
 */
enum RejectionReason: string
{
    // Criteria (inclusion gates).
    case DiscountBelowMin = 'discount-below-min';
    case PriceOutOfBand = 'price-out-of-band';
    case SalesRankTooHigh = 'sales-rank-too-high';
    case CategoryNotAllowed = 'category-not-allowed';
    case RatingBelowMin = 'rating-below-min';

    // Outlier Guards (exclusion proofs).
    case SpikePollutedBaseline = 'spike-polluted-baseline';
    case NoDemand = 'no-demand';
    case AbsPriceFloor = 'abs-price-floor';
    case AbsurdClaim = 'absurd-claim';
}
