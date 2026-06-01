<?php

declare(strict_types=1);

namespace App\PreFilter;

use DealPromoter\Shared\PreFilter\Criteria;
use DealPromoter\Shared\PreFilter\GuardThresholds;
use DealPromoter\Shared\PreFilter\PreFilter;

/**
 * Builds a {@see PreFilter} from config-driven thresholds (config/packages/
 * pre_filter.yaml). Realizes P4's "thresholds in config, no code change":
 * editing the yaml changes the surviving set via {@see Criteria::fromArray()} /
 * {@see GuardThresholds::fromArray()}, with no PHP touched.
 */
final readonly class PreFilterFactory
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $guards
     */
    public function __construct(
        private array $criteria,
        private array $guards,
    ) {
    }

    public function create(): PreFilter
    {
        return new PreFilter(
            Criteria::fromArray($this->criteria),
            GuardThresholds::fromArray($this->guards),
        );
    }
}
