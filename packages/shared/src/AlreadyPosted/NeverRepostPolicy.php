<?php

declare(strict_types=1);

namespace DealPromoter\Shared\AlreadyPosted;

/**
 * Default Repost Policy: a previously-posted ASIN is always suppressed,
 * regardless of when it was last posted or how much the price has moved.
 *
 * This is the safe default. Future slices may introduce a cooldown window
 * by swapping in a time-aware implementation bound in services.yaml.
 */
final readonly class NeverRepostPolicy implements RepostPolicy
{
    public function allowsRepost(string $asin): bool
    {
        return false;
    }
}
