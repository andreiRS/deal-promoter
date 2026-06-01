<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Keepa;

/**
 * One fetched page of Keepa deals: the parsed Candidates plus the token meter
 * read off the same response, so the caller can budget the next call.
 */
final readonly class DealPage
{
    /**
     * @param list<Candidate> $candidates
     */
    public function __construct(
        public array $candidates,
        public TokenMeter $meter,
    ) {
    }
}
