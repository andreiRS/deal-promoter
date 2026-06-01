<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Storage;

/**
 * Recorded Price History: the durable log of deals we have already published
 * (the `posted_deal` table). This is the swappable persistence seam consumed by
 * the Already-Posted Guard (P6) so it never depends on Doctrine directly.
 *
 * Reads land here now; writes are appended by the publish step (P9) and are
 * intentionally out of scope for this contract.
 */
interface RecordedPriceHistory
{
    /**
     * True when the given ASIN has already been posted (exists in Recorded
     * Price History). Used by the Already-Posted Guard to skip re-posting.
     */
    public function hasPostedAsin(string $asin): bool;
}
