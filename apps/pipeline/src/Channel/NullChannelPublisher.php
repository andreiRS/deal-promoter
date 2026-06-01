<?php

declare(strict_types=1);

namespace App\Channel;

use DealPromoter\Shared\Channel\ChannelPublisher;
use DealPromoter\Shared\Channel\PublishableDeal;
use Psr\Log\LoggerInterface;

/**
 * Stub publisher: logs intent and does nothing else.
 *
 * This is the only ChannelPublisher implementation wired for now. Drop in a
 * real implementation (e.g. WahaChannelPublisher) by rebinding the
 * ChannelPublisher service alias in services.yaml — no controller or template
 * change required.
 *
 * It makes NO WhatsApp/WAHA/HTTP call and does NOT write the DB.
 * The posted_deal row is written only by a future real publisher after a
 * successful channel delivery.
 */
final readonly class NullChannelPublisher implements ChannelPublisher
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function publish(PublishableDeal $deal): void
    {
        $this->logger->info(
            'Publish requested (null channel) for ASIN {asin}',
            [
                'asin' => $deal->getAsin(),
                'title' => $deal->getTitle(),
                'snapshot_price_cents' => $deal->getSnapshotPriceCents(),
                'affiliate_url' => $deal->getAffiliateUrl(),
            ],
        );
    }
}
