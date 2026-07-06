<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Creators;

/**
 * A live, buy-box offer snapshot for one ASIN, taken from the Amazon CreatorsAPI
 * GetItems response at snapshot time. Maps 1:1 onto P2's `found_deal`
 * snapshot columns.
 *
 * Money is integer euro-cents: the boundary converts the API's decimal-euro
 * `Money.amount` once via `(int) round($amount * 100)`; the float never crosses
 * into the domain.
 *
 * `detailPageUrl` is the affiliate `detailPageURL` passed through verbatim: it
 * carries the partner `tag=` and `linkCode=ogi` and must not be rebuilt.
 */
final readonly class LiveSnapshot
{
    public function __construct(
        public string $asin,
        public int $priceCents,
        public ?string $availability,
        public ?string $condition,
        public ?string $merchantId,
        public ?int $savingsPercent,
        public ?string $savingBasisType,
        public bool $hasDealDetails,
        public ?bool $violatesMap,
        public string $detailPageUrl,
    ) {
    }

    /**
     * Amazon's own evidence that a discount is real: a `dealDetails` badge/window
     * OR a `savingBasis` of type `WAS_PRICE` (Amazon's recent actual selling
     * price). Either signal alone counts. A `LIST_PRICE` basis is seller-set MSRP,
     * gameable, and so is NOT Amazon-verified. The only trustworthy on-snapshot
     * source of Discount Magnitude; rare (~1 in 10 snapshotted items).
     */
    public function isAmazonVerified(): bool
    {
        return $this->hasDealDetails || 'WAS_PRICE' === $this->savingBasisType;
    }
}
