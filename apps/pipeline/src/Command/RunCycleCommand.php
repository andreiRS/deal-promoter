<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CycleRun;
use App\Entity\FoundDeal;
use DealPromoter\Shared\AlreadyPosted\AlreadyPostedGuard;
use DealPromoter\Shared\Creators\CreatorsClient;
use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Keepa\KeepaDiscovery;
use DealPromoter\Shared\PreFilter\PreFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs one Cycle of the Deal Pipeline end-to-end, up to and including record:
 * discover raw candidates -> Pre-filter -> Already-Posted Guard -> Live Snapshot
 * -> persist a CycleRun with its found-deal rows.
 *
 * This slice stops at record: NO Deal Gate, NO publish/skip verdict is computed
 * or stored. It only captures raw Keepa signals and Live Snapshot facts.
 *
 * Guarded by a non-blocking run-lock so two Cycles never overlap, and fail-safe:
 * a dependency error (Keepa or Creators) skips the whole Cycle with no partial
 * row persisted and exits non-zero.
 */
#[AsCommand(
    name: 'app:run-cycle',
    description: 'Run one Cycle of the Deal Pipeline (discover, filter, snapshot, record).',
)]
final class RunCycleCommand extends Command
{
    private const string LOCK_KEY = 'deal-pipeline-cycle';

    public function __construct(
        private readonly KeepaDiscovery $discovery,
        private readonly PreFilter $preFilter,
        private readonly AlreadyPostedGuard $alreadyPostedGuard,
        private readonly CreatorsClient $creators,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 1. Acquire the run-lock (non-blocking). A second Cycle is rejected.
        $lock = $this->lockFactory->createLock(self::LOCK_KEY);
        if (!$lock->acquire()) {
            $io->warning('A Cycle is already running (run-lock held); skipping this Cycle.');

            return Command::SUCCESS;
        }

        try {
            return $this->runCycle($io);
        } catch (\Throwable $error) {
            // Fail-safe: skip-and-retry. Nothing is flushed before this point on
            // the dependency-error paths, so no partial/corrupt Cycle persists.
            $io->error(\sprintf('Cycle skipped after error: %s', $error->getMessage()));

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function runCycle(SymfonyStyle $io): int
    {
        $startedAt = new \DateTimeImmutable();

        // 2. Discover raw candidates.
        $rawCandidates = $this->discovery->fetchDealPage()->candidates;
        $rawCount = \count($rawCandidates);

        // 3. Pre-filter, then Already-Posted Guard -> surviving candidates.
        $preFiltered = $this->preFilter->apply(...$rawCandidates)->survivors;
        $survivors = $this->alreadyPostedGuard->apply(...$preFiltered)->survivors;
        $survivingCount = \count($survivors);

        // 4. Live Snapshot for the surviving ASINs. A survivor with a snapshot
        //    becomes a found deal; a survivor absent from the map is skipped.
        $asins = array_map(static fn (Candidate $c): string => $c->asin, $survivors);
        $snapshots = [] === $asins ? [] : $this->creators->fetchSnapshots(...$asins);

        // 5. Record one CycleRun plus one FoundDeal per found deal.
        $cycleRun = new CycleRun($startedAt);
        $snapshottedCount = 0;
        foreach ($survivors as $candidate) {
            $snapshot = $snapshots[$candidate->asin] ?? null;
            if (null === $snapshot) {
                continue;
            }

            $foundDeal = new FoundDeal($candidate->asin, $candidate->title, $startedAt);
            $foundDeal->setImageUrl($candidate->imageUrl);
            $foundDeal->setKeepaAvg90Cents($candidate->avg90Cents);
            $foundDeal->setKeepaDropPct($candidate->dropPercent90);
            $foundDeal->setSnapshotPriceCents($snapshot->priceCents);
            $foundDeal->setAvailability($snapshot->availability);
            $foundDeal->setCondition($snapshot->condition);
            $foundDeal->setMerchantId($snapshot->merchantId);
            $foundDeal->setAmazonSavingsPct($snapshot->savingsPercent);
            $foundDeal->setSavingBasisType($snapshot->savingBasisType);
            $foundDeal->setHasDealDetails($snapshot->hasDealDetails);
            $foundDeal->setViolatesMap($snapshot->violatesMap);
            $foundDeal->setAffiliateUrl($snapshot->detailPageUrl);

            $cycleRun->addFoundDeal($foundDeal);
            ++$snapshottedCount;
        }

        $cycleRun->setRawCount($rawCount);
        $cycleRun->setSurvivingCount($survivingCount);
        $cycleRun->setSnapshottedCount($snapshottedCount);
        $cycleRun->finish(new \DateTimeImmutable());

        $this->entityManager->persist($cycleRun);
        $this->entityManager->flush();

        $io->success(\sprintf(
            'Cycle complete: %d raw candidates, %d surviving candidates, %d found deals recorded.',
            $rawCount,
            $survivingCount,
            $snapshottedCount,
        ));

        return Command::SUCCESS;
    }
}
