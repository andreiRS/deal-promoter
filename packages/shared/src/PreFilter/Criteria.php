<?php

declare(strict_types=1);

namespace DealPromoter\Shared\PreFilter;

use DealPromoter\Shared\Config\Coerce;

/**
 * Positive inclusion thresholds: "is this the kind of deal we want?".
 *
 * Every field is a tunable threshold so the Pre-filter's behaviour is fully
 * config-driven with no code change. The {@see self::fromArray()} factory is the
 * config seam; Symfony service/YAML binding wires it in P7 (out of scope here).
 *
 * Null / empty conventions:
 *   - maxPriceCents null .............. no upper price cap
 *   - maxSalesRank null ............... no sales-rank limit
 *   - allowedRootCategories [] ........ allow any root category
 *   - minRatingStarsTimesTen null ..... no rating minimum
 */
final readonly class Criteria
{
    /**
     * @param list<int> $allowedRootCategories Keepa root category node ids; empty = allow all
     */
    public function __construct(
        public int $minDiscountPercent = 20,
        public int $minPriceCents = 0,
        public ?int $maxPriceCents = null,
        public ?int $maxSalesRank = null,
        public array $allowedRootCategories = [],
        public ?int $minRatingStarsTimesTen = null,
    ) {
    }

    /**
     * Build from a loosely typed config map; absent keys keep the defaults.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $defaults = new self();

        return new self(
            minDiscountPercent: Coerce::int($config, 'minDiscountPercent', $defaults->minDiscountPercent),
            minPriceCents: Coerce::int($config, 'minPriceCents', $defaults->minPriceCents),
            maxPriceCents: Coerce::nullableInt($config, 'maxPriceCents', $defaults->maxPriceCents),
            maxSalesRank: Coerce::nullableInt($config, 'maxSalesRank', $defaults->maxSalesRank),
            allowedRootCategories: Coerce::intList($config, 'allowedRootCategories', $defaults->allowedRootCategories),
            minRatingStarsTimesTen: Coerce::nullableInt($config, 'minRatingStarsTimesTen', $defaults->minRatingStarsTimesTen),
        );
    }
}
