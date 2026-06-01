<?php

declare(strict_types=1);

namespace DealPromoter\Shared\PreFilter;

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
            minDiscountPercent: self::intOr($config, 'minDiscountPercent', $defaults->minDiscountPercent),
            minPriceCents: self::intOr($config, 'minPriceCents', $defaults->minPriceCents),
            maxPriceCents: self::nullableIntOr($config, 'maxPriceCents', $defaults->maxPriceCents),
            maxSalesRank: self::nullableIntOr($config, 'maxSalesRank', $defaults->maxSalesRank),
            allowedRootCategories: self::intListOr($config, 'allowedRootCategories', $defaults->allowedRootCategories),
            minRatingStarsTimesTen: self::nullableIntOr($config, 'minRatingStarsTimesTen', $defaults->minRatingStarsTimesTen),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intOr(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nullableIntOr(array $config, string $key, ?int $default): ?int
    {
        if (!\array_key_exists($key, $config)) {
            return $default;
        }
        $value = $config[$key];

        return \is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int>            $default
     *
     * @return list<int>
     */
    private static function intListOr(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? null;
        if (!\is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => \is_int($v)));
    }
}
