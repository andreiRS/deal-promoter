<?php

declare(strict_types=1);

namespace DealPromoter\Shared\PreFilter;

/**
 * Tunables for the four Outlier Guards. Defaults are the constants the funnel
 * experiments settled on (see experiments/05-funnel-dryrun/analyze.ts).
 *
 * Like {@see Criteria}, every value is config-driven via {@see self::fromArray()};
 * Symfony binding lands in P7.
 */
final readonly class GuardThresholds
{
    public function __construct(
        public float $spikeRatio = 3.0,
        public int $minSalesRankDrops90 = 1,
        public int $absPriceFloorCents = 200,
        public int $maxClaimedDropPercent = 97,
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
            spikeRatio: self::floatOr($config, 'spikeRatio', $defaults->spikeRatio),
            minSalesRankDrops90: self::intOr($config, 'minSalesRankDrops90', $defaults->minSalesRankDrops90),
            absPriceFloorCents: self::intOr($config, 'absPriceFloorCents', $defaults->absPriceFloorCents),
            maxClaimedDropPercent: self::intOr($config, 'maxClaimedDropPercent', $defaults->maxClaimedDropPercent),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function floatOr(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? null;
        if (\is_int($value)) {
            return (float) $value;
        }

        return \is_float($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intOr(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }
}
