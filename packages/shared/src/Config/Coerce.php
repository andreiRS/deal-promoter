<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Config;

/**
 * Coerces values out of a loosely typed config/response map (`array<string, mixed>`)
 * into typed values, falling back to a default when the key is absent or the wrong
 * shape. The one tested home for the "untrusted map in, typed value out" rule that
 * {@see \DealPromoter\Shared\PreFilter\Criteria}, {@see \DealPromoter\Shared\PreFilter\GuardThresholds}
 * and {@see \DealPromoter\Shared\Keepa\TokenMeter} all need.
 */
final class Coerce
{
    /**
     * Strict int: a non-int value (including a numeric string) yields the default.
     *
     * @param array<string, mixed> $config
     */
    public static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    /**
     * Like {@see self::int()} but distinguishes "key absent" (keep the default)
     * from "key present but not an int" (null). Used where null is meaningful,
     * e.g. an explicit "no cap".
     *
     * @param array<string, mixed> $config
     */
    public static function nullableInt(array $config, string $key, ?int $default): ?int
    {
        if (!\array_key_exists($key, $config)) {
            return $default;
        }
        $value = $config[$key];

        return \is_int($value) ? $value : null;
    }

    /**
     * Float, accepting an int as a float. Anything else yields the default.
     *
     * @param array<string, mixed> $config
     */
    public static function float(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? null;
        if (\is_int($value)) {
            return (float) $value;
        }

        return \is_float($value) ? $value : $default;
    }

    /**
     * A list of ints, dropping any non-int entry. A non-array yields the default.
     *
     * @param array<string, mixed> $config
     * @param list<int>            $default
     *
     * @return list<int>
     */
    public static function intList(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? null;
        if (!\is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => \is_int($v)));
    }

    /**
     * Lenient int: casts any numeric value (e.g. a JSON number that decoded as a
     * float or numeric string) to int. A non-numeric value yields the default.
     *
     * @param array<string, mixed> $config
     */
    public static function numericInt(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
