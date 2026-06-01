<?php

declare(strict_types=1);

namespace App\Entity;

use DealPromoter\Shared\Channel\PublishableDeal;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Candidate that survived the Pre-Filter, recorded against its CycleRun.
 *
 * The snapshot columns (price, availability, condition, merchant, savings, deal
 * flags, affiliate URL) are nullable: they are populated later by the Live
 * Snapshot (P5/P7). All money is integer euro-cents; percentages are integers.
 *
 * Implements PublishableDeal so it can be passed directly to any ChannelPublisher
 * without an extra adapter; the four required getters already existed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'found_deal')]
#[ORM\Index(name: 'idx_found_deal_asin', columns: ['asin'])]
class FoundDeal implements PublishableDeal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CycleRun::class, inversedBy: 'foundDeals')]
    #[ORM\JoinColumn(name: 'cycle_run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CycleRun $cycleRun;

    #[ORM\Column(type: 'string', length: 16)]
    private string $asin;

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(name: 'image_url', type: 'text', nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'keepa_avg90_cents', type: 'integer', nullable: true)]
    private ?int $keepaAvg90Cents = null;

    #[ORM\Column(name: 'keepa_drop_pct', type: 'integer', nullable: true)]
    private ?int $keepaDropPct = null;

    #[ORM\Column(name: 'snapshot_price_cents', type: 'integer', nullable: true)]
    private ?int $snapshotPriceCents = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $availability = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $condition = null;

    #[ORM\Column(name: 'merchant_id', type: 'string', length: 64, nullable: true)]
    private ?string $merchantId = null;

    #[ORM\Column(name: 'amazon_savings_pct', type: 'integer', nullable: true)]
    private ?int $amazonSavingsPct = null;

    #[ORM\Column(name: 'saving_basis_type', type: 'string', length: 64, nullable: true)]
    private ?string $savingBasisType = null;

    #[ORM\Column(name: 'has_deal_details', type: 'boolean', nullable: true)]
    private ?bool $hasDealDetails = null;

    #[ORM\Column(name: 'violates_map', type: 'boolean', nullable: true)]
    private ?bool $violatesMap = null;

    #[ORM\Column(name: 'affiliate_url', type: 'text', nullable: true)]
    private ?string $affiliateUrl = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * Set by the Publish endpoint stub (P9) to mark that publish was requested.
     * Kept distinct from posted_deal: this records intent; a real ChannelPublisher
     * writes a posted_deal row only after a successful channel delivery.
     */
    #[ORM\Column(name: 'publish_requested_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishRequestedAt = null;

    public function __construct(string $asin, string $title, \DateTimeImmutable $createdAt)
    {
        $this->asin = $asin;
        $this->title = $title;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCycleRun(): CycleRun
    {
        return $this->cycleRun;
    }

    public function setCycleRun(CycleRun $cycleRun): void
    {
        $this->cycleRun = $cycleRun;
    }

    public function getAsin(): string
    {
        return $this->asin;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }

    public function getKeepaAvg90Cents(): ?int
    {
        return $this->keepaAvg90Cents;
    }

    public function setKeepaAvg90Cents(?int $keepaAvg90Cents): void
    {
        $this->keepaAvg90Cents = $keepaAvg90Cents;
    }

    public function getKeepaDropPct(): ?int
    {
        return $this->keepaDropPct;
    }

    public function setKeepaDropPct(?int $keepaDropPct): void
    {
        $this->keepaDropPct = $keepaDropPct;
    }

    public function getSnapshotPriceCents(): ?int
    {
        return $this->snapshotPriceCents;
    }

    public function setSnapshotPriceCents(?int $snapshotPriceCents): void
    {
        $this->snapshotPriceCents = $snapshotPriceCents;
    }

    public function getAvailability(): ?string
    {
        return $this->availability;
    }

    public function setAvailability(?string $availability): void
    {
        $this->availability = $availability;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function setCondition(?string $condition): void
    {
        $this->condition = $condition;
    }

    public function getMerchantId(): ?string
    {
        return $this->merchantId;
    }

    public function setMerchantId(?string $merchantId): void
    {
        $this->merchantId = $merchantId;
    }

    public function getAmazonSavingsPct(): ?int
    {
        return $this->amazonSavingsPct;
    }

    public function setAmazonSavingsPct(?int $amazonSavingsPct): void
    {
        $this->amazonSavingsPct = $amazonSavingsPct;
    }

    public function getSavingBasisType(): ?string
    {
        return $this->savingBasisType;
    }

    public function setSavingBasisType(?string $savingBasisType): void
    {
        $this->savingBasisType = $savingBasisType;
    }

    public function getHasDealDetails(): ?bool
    {
        return $this->hasDealDetails;
    }

    public function setHasDealDetails(?bool $hasDealDetails): void
    {
        $this->hasDealDetails = $hasDealDetails;
    }

    public function getViolatesMap(): ?bool
    {
        return $this->violatesMap;
    }

    public function setViolatesMap(?bool $violatesMap): void
    {
        $this->violatesMap = $violatesMap;
    }

    public function getAffiliateUrl(): ?string
    {
        return $this->affiliateUrl;
    }

    public function setAffiliateUrl(?string $affiliateUrl): void
    {
        $this->affiliateUrl = $affiliateUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPublishRequestedAt(): ?\DateTimeImmutable
    {
        return $this->publishRequestedAt;
    }

    public function markPublishRequested(\DateTimeImmutable $at): void
    {
        $this->publishRequestedAt = $at;
    }
}
