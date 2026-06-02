<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Channel;

/**
 * The seam onto any downstream publish channel (WhatsApp, email, etc.).
 *
 * Implementations live app-side so they can depend on Psr\Log, HTTP clients,
 * or any other infrastructure without polluting the shared package. The
 * controller and template depend only on this interface; swapping in a real
 * implementation (e.g. HttpChannelPublisher) requires no change to either.
 */
interface ChannelPublisher
{
    public function publish(PublishableDeal $deal): void;
}
