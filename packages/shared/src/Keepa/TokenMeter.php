<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Keepa;

use DealPromoter\Shared\Config\Coerce;

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
            tokensLeft: Coerce::numericInt($body, 'tokensLeft', 0),
            tokensConsumed: Coerce::numericInt($body, 'tokensConsumed', 0),
            refillRate: Coerce::numericInt($body, 'refillRate', 0),
            refillIn: Coerce::numericInt($body, 'refillIn', 0),
        );
    }
}
