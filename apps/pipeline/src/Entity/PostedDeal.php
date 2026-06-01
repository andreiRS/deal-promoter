<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Recorded Price History row: a deal that was actually published. Reserved here;
 * rows are written by the publish step (P9). Read by the Already-Posted Guard
 * (P6) via the RecordedPriceHistory storage seam.
 *
 * `price_cents` is integer euro-cents.
 */
#[ORM\Entity]
#[ORM\Table(name: 'posted_deal')]
#[ORM\Index(name: 'idx_posted_deal_asin', columns: ['asin'])]
class PostedDeal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $asin;

    #[ORM\Column(name: 'price_cents', type: 'integer')]
    private int $priceCents;

    #[ORM\Column(name: 'posted_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $postedAt;

    public function __construct(string $asin, int $priceCents, \DateTimeImmutable $postedAt)
    {
        $this->asin = $asin;
        $this->priceCents = $priceCents;
        $this->postedAt = $postedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsin(): string
    {
        return $this->asin;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function getPostedAt(): \DateTimeImmutable
    {
        return $this->postedAt;
    }
}
