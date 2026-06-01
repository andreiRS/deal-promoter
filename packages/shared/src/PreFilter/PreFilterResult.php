<?php

declare(strict_types=1);

namespace DealPromoter\Shared\PreFilter;

use DealPromoter\Shared\Keepa\Candidate;

/**
 * Outcome of running the Pre-filter over a batch of Candidates: the surviving
 * candidates and the rejected ones (each with its reasons). Together they
 * partition the input one-to-one.
 */
final readonly class PreFilterResult
{
    /**
     * @param list<Candidate> $survivors  candidates that matched all criteria and passed all guards
     * @param list<Rejection> $rejections dropped candidates, each with a non-empty reason list
     */
    public function __construct(
        public array $survivors,
        public array $rejections,
    ) {
    }

    /**
     * Diagnostic tally of how often each reason fired across all rejections.
     * One rejected candidate with N reasons contributes to N buckets.
     *
     * @return array<string, int> reason value => count
     */
    public function reasonCounts(): array
    {
        $counts = [];
        foreach ($this->rejections as $rejection) {
            foreach ($rejection->reasons as $reason) {
                $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
