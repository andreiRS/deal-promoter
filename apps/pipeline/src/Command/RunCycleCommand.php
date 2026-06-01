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
use DealPromoter\Shared\PreFilter\PreFilterResult;
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
        $io->writeln(
            \sprintf('<info>Discover:</info> %d raw candidates from Keepa.', $rawCount),
            OutputInterface::VERBOSITY_VERBOSE,
        );

        // 3. Pre-filter, then Already-Posted Guard -> surviving candidates.
        $preFilterResult = $this->preFilter->apply(...$rawCandidates);
        $preFiltered = $preFilterResult->survivors;
        $this->reportPreFilter($io, $rawCount, $preFilterResult);

        $survivors = $this->alreadyPostedGuard->apply(...$preFiltered)->survivors;
        $survivingCount = \count($survivors);
        $io->writeln(
            \sprintf(
                '<info>Already-Posted Guard:</info> %d -> %d survivors (%d already posted).',
                \count($preFiltered),
                $survivingCount,
                \count($preFiltered) - $survivingCount,
            ),
            OutputInterface::VERBOSITY_VERBOSE,
        );

        // 4. Live Snapshot for the surviving ASINs. A survivor absent from the map
        //    is skipped; a snapshot confirms Price Validity but only an
        //    Amazon-attested one (dealDetails / WAS_PRICE) becomes a found deal.
        $asins = array_map(static fn (Candidate $c): string => $c->asin, $survivors);
        $snapshots = [] === $asins ? [] : $this->creators->fetchSnapshots(...$asins);
        $io->writeln(
            \sprintf(
                '<info>Live Snapshot:</info> %d ASINs sent, %d snapshots returned.',
                \count($asins),
                \count($snapshots),
            ),
            OutputInterface::VERBOSITY_VERBOSE,
        );

        // 5. Record one CycleRun plus one FoundDeal per attested deal. Strict dial:
        //    a price-valid-but-unattested snapshot is counted, not published, so
        //    `snapshottedCount` (Price Validity) stays distinct from the attested
        //    found-deal rows (`count($cycleRun->getFoundDeals())`).
        $cycleRun = new CycleRun($startedAt);
        $snapshottedCount = 0;
        $snapshotRows = [];
        foreach ($survivors as $candidate) {
            $snapshot = $snapshots[$candidate->asin] ?? null;
            if (null === $snapshot) {
                continue;
            }
            ++$snapshottedCount;

            $attested = $snapshot->hasAmazonAttestation();
            if ($attested) {
                $cycleRun->addFoundDeal(FoundDeal::fromSnapshot($candidate, $snapshot, $startedAt));
            }

            $snapshotRows[] = [
                $snapshot->asin,
                $this->euros($snapshot->priceCents),
                $snapshot->availability ?? '—',
                $snapshot->savingBasisType ?? '—',
                $snapshot->hasDealDetails ? 'yes' : 'no',
                $attested ? '<info>✓ attested</info>' : '<comment>skipped</comment>',
            ];
        }
        $attestedCount = \count($cycleRun->getFoundDeals());
        $this->reportSnapshots($io, $snapshotRows);

        $cycleRun->setRawCount($rawCount);
        $cycleRun->setSurvivingCount($survivingCount);
        $cycleRun->setSnapshottedCount($snapshottedCount);
        $cycleRun->finish(new \DateTimeImmutable());

        $this->entityManager->persist($cycleRun);
        $this->entityManager->flush();

        $io->success(\sprintf(
            'Cycle complete: %d raw candidates, %d surviving, %d snapshotted, %d attested deals recorded.',
            $rawCount,
            $survivingCount,
            $snapshottedCount,
            $attestedCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Verbose-only: how many candidates the Pre-filter dropped and which reasons
     * fired most (one candidate can trip several gates, so the tally exceeds the
     * rejected count).
     */
    private function reportPreFilter(SymfonyStyle $io, int $rawCount, PreFilterResult $result): void
    {
        if (!$io->isVerbose()) {
            return;
        }

        $survived = \count($result->survivors);
        $io->writeln(\sprintf(
            '<info>Pre-filter:</info> %d -> %d survivors (%d rejected).',
            $rawCount,
            $survived,
            \count($result->rejections),
        ));

        $counts = $result->reasonCounts();
        arsort($counts);
        foreach ($counts as $reason => $count) {
            $io->writeln(\sprintf('  · %s: %d', $reason, $count), OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
    }

    /**
     * Verbose-only: one row per snapshotted survivor showing the attestation
     * signals (savingBasis + dealDetails) and whether the Strict dial recorded it.
     * This is the window into "snapshotted but not attested" the DB no longer
     * keeps, since unattested snapshots are dropped rather than persisted.
     *
     * @param list<array{string, string, string, string, string, string}> $rows
     */
    private function reportSnapshots(SymfonyStyle $io, array $rows): void
    {
        if (!$io->isVerbose() || [] === $rows) {
            return;
        }

        $io->table(
            ['ASIN', 'Price', 'Availability', 'SavingBasis', 'DealDetails', 'Attestation'],
            $rows,
        );
    }

    private function euros(int $cents): string
    {
        return \sprintf('%.2f €', $cents / 100);
    }
}
