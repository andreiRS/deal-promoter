<?php

declare(strict_types=1);

namespace DealPromoter\Shared\AlreadyPosted;

use DealPromoter\Shared\Keepa\Candidate;

/**
 * Outcome of running the Already-Posted Guard over a batch of Candidates.
 * Together, survivors and suppressed partition the input one-to-one.
 */
final readonly class AlreadyPostedResult
{
    /**
     * @param list<Candidate> $survivors  candidates whose ASIN was not in Recorded Price History,
     *                                    or whose RepostPolicy allowed a re-post
     * @param list<Candidate> $suppressed candidates whose ASIN was found in Recorded Price History
     *                                    and whose RepostPolicy denied a re-post
     */
    public function __construct(
        public array $survivors,
        public array $suppressed,
    ) {
    }
}
