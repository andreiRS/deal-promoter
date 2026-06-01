<?php

declare(strict_types=1);

namespace DealPromoter\Shared\AlreadyPosted;

/**
 * Repost Policy: the minimal hook that decides whether an already-posted ASIN
 * may be re-posted in the current cycle.
 *
 * Implementations replace the default {@see NeverRepostPolicy} when a time-based
 * or campaign-based cooldown window is introduced (P-future).
 */
interface RepostPolicy
{
    /**
     * Returns true when the given ASIN is eligible to be re-posted despite
     * appearing in Recorded Price History.
     */
    public function allowsRepost(string $asin): bool;
}
