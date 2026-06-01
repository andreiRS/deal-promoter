<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Money;

/**
 * Integer euro-cents helpers. All money crosses our boundaries as integer cents;
 * this is the one place that knows how to compare them safely.
 */
final class Cents
{
    /**
     * Two cent values are "equal" when they differ by at most $toleranceCents.
     * Keepa's `avg*` and a product's `stats.*` are the same figure rounded
     * differently, so they must be compared within a cent, never with ===.
     */
    public static function equalWithinTolerance(int $a, int $b, int $toleranceCents = 1): bool
    {
        return abs($a - $b) <= $toleranceCents;
    }
}
