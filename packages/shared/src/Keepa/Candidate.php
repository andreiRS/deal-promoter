<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Keepa;

/**
 * A raw deal item straight from Keepa's `/deal` endpoint, decoded into our own
 * domain shape. Untrusted on price until a Live Snapshot re-confirms it.
 *
 * Prices are integer euro-cents (the marketplace minor unit). A null price field
 * means Keepa reported a sentinel (no data / out of stock), never a real €0.00.
 */
final readonly class Candidate
{
    /**
     * @param list<int> $categories Keepa category node ids
     */
    public function __construct(
        public string $asin,
        public string $title,
        public string $imageUrl,
        public ?int $currentPriceCents,
        public ?int $avg30Cents,
        public ?int $avg90Cents,
        public ?int $dropPercent90,
        public ?int $salesRankDrops90,
        public ?int $salesRank,
        public ?int $ratingStarsTimesTen,
        public int $rootCategory,
        public array $categories,
    ) {
    }
}
