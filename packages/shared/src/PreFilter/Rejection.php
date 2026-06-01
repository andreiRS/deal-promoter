<?php

declare(strict_types=1);

namespace DealPromoter\Shared\PreFilter;

use DealPromoter\Shared\Keepa\Candidate;

/**
 * A Candidate the Pre-filter dropped, together with every reason that fired.
 * The reason list is always non-empty.
 */
final readonly class Rejection
{
    /**
     * @param non-empty-list<RejectionReason> $reasons every criterion/guard that fired
     */
    public function __construct(
        public Candidate $candidate,
        public array $reasons,
    ) {
    }
}
