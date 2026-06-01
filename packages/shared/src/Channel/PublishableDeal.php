<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Channel;

/**
 * The read-only view of a deal that a ChannelPublisher receives.
 *
 * Keeping the interface narrow decouples publishers from the App\Entity
 * layer: any object that implements these four getters can be published,
 * including test fakes and future adapters.
 */
interface PublishableDeal
{
    public function getAsin(): string;

    public function getTitle(): string;

    public function getSnapshotPriceCents(): ?int;

    public function getAffiliateUrl(): ?string;
}
