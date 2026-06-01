<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One Cycle: a single discovery-to-review run. Holds the funnel counts and owns
 * the FoundDeal rows it produced.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cycle_run')]
class CycleRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'raw_count', type: 'integer')]
    private int $rawCount = 0;

    #[ORM\Column(name: 'surviving_count', type: 'integer')]
    private int $survivingCount = 0;

    #[ORM\Column(name: 'snapshotted_count', type: 'integer')]
    private int $snapshottedCount = 0;

    /**
     * @var Collection<int, FoundDeal>
     */
    #[ORM\OneToMany(targetEntity: FoundDeal::class, mappedBy: 'cycleRun', cascade: ['persist'], orphanRemoval: true)]
    private Collection $foundDeals;

    public function __construct(\DateTimeImmutable $startedAt)
    {
        $this->startedAt = $startedAt;
        $this->foundDeals = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function finish(\DateTimeImmutable $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getRawCount(): int
    {
        return $this->rawCount;
    }

    public function setRawCount(int $rawCount): void
    {
        $this->rawCount = $rawCount;
    }

    public function getSurvivingCount(): int
    {
        return $this->survivingCount;
    }

    public function setSurvivingCount(int $survivingCount): void
    {
        $this->survivingCount = $survivingCount;
    }

    public function getSnapshottedCount(): int
    {
        return $this->snapshottedCount;
    }

    public function setSnapshottedCount(int $snapshottedCount): void
    {
        $this->snapshottedCount = $snapshottedCount;
    }

    /**
     * @return Collection<int, FoundDeal>
     */
    public function getFoundDeals(): Collection
    {
        return $this->foundDeals;
    }

    public function addFoundDeal(FoundDeal $foundDeal): void
    {
        if (!$this->foundDeals->contains($foundDeal)) {
            $this->foundDeals->add($foundDeal);
            $foundDeal->setCycleRun($this);
        }
    }
}
