<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Keepa;

/**
 * The live token meter Keepa rides on (nearly) every response body, not headers.
 * The entry tier is ~20 tokens/min; a `/deal` page costs a flat 5.
 */
final readonly class TokenMeter
{
    public function __construct(
        public int $tokensLeft,
        public int $tokensConsumed,
        public int $refillRate,
        public int $refillIn,
    ) {
    }

    /**
     * @param array<string, mixed> $body the decoded Keepa response body
     */
    public static function fromResponse(array $body): self
    {
        return new self(
            tokensLeft: self::intOf($body, 'tokensLeft'),
            tokensConsumed: self::intOf($body, 'tokensConsumed'),
            refillRate: self::intOf($body, 'refillRate'),
            refillIn: self::intOf($body, 'refillIn'),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function intOf(array $body, string $key): int
    {
        $value = $body[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
