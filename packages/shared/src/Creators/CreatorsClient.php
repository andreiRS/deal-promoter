<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Creators;

/**
 * The pipeline's seam onto the Amazon CreatorsAPI GetItems operation. The
 * pipeline depends on this interface, never on the official SDK; the SDK-backed
 * implementation lives app-side.
 */
interface CreatorsClient
{
    /**
     * Fetch a live buy-box snapshot for each given ASIN.
     *
     * Contract:
     *  - The result is keyed by ASIN. ASINs with no buy-box-winner listing, or
     *    that the API reported as errors, are simply absent from the map (the
     *    caller diffs requested vs. returned to detect failures).
     *  - Each present value is the buy-box-winner listing only (the listing with
     *    `isBuyBoxWinner === true`), never the cheapest or the first listing.
     *  - Implementations batch the request to respect the API's per-call ASIN
     *    limit and any configured cap.
     *
     * @return array<string, LiveSnapshot> snapshots keyed by ASIN
     */
    public function fetchSnapshots(string ...$asins): array;
}
