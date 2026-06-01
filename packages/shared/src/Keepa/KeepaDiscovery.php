<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Keepa;

/**
 * The pipeline's seam onto Keepa discovery: fetch one page of raw Candidates.
 *
 * The Cycle depends on this interface, never on the concrete {@see KeepaClient},
 * so it can run in tests without a live Keepa call. The HTTP-backed
 * implementation is {@see KeepaClient}.
 */
interface KeepaDiscovery
{
    /**
     * Fetch one `/deal` page of raw Candidates (up to 150) for a flat token cost.
     */
    public function fetchDealPage(int $page = 0): DealPage;
}
