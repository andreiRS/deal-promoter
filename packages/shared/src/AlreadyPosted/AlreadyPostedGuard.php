<?php

declare(strict_types=1);

namespace DealPromoter\Shared\AlreadyPosted;

use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Storage\RecordedPriceHistory;

/**
 * Already-Posted Guard: filters out Candidates whose ASIN already appears in
 * Recorded Price History, unless the Repost Policy explicitly allows a re-post.
 *
 * A Candidate is suppressed iff:
 *   - {@see RecordedPriceHistory::hasPostedAsin()} returns true for its ASIN, AND
 *   - {@see RepostPolicy::allowsRepost()} returns false for that ASIN.
 *
 * All storage access goes through the RecordedPriceHistory interface — this class
 * never imports Doctrine or any infrastructure layer.
 */
final readonly class AlreadyPostedGuard
{
    public function __construct(
        private RecordedPriceHistory $history,
        private RepostPolicy $policy,
    ) {
    }

    /**
     * Partition the given candidates into survivors and suppressed.
     *
     * Accepts candidates variadically: `apply($a, $b)` or `apply(...$list)`.
     */
    public function apply(Candidate ...$candidates): AlreadyPostedResult
    {
        $survivors = [];
        $suppressed = [];

        foreach ($candidates as $candidate) {
            if ($this->isSuppressed($candidate)) {
                $suppressed[] = $candidate;
            } else {
                $survivors[] = $candidate;
            }
        }

        return new AlreadyPostedResult($survivors, $suppressed);
    }

    private function isSuppressed(Candidate $candidate): bool
    {
        return $this->history->hasPostedAsin($candidate->asin)
            && !$this->policy->allowsRepost($candidate->asin);
    }
}
