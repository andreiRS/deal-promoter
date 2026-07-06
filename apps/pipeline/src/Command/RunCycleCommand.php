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

    /** Flat Keepa token cost of one `/deal` page; we stop before we can't fund one. */
    private const int KEEPA_PAGE_COST = 5;

    /**
     * @param int $pagesPerCycle number of Keepa `/deal` pages fetched per Cycle.
     *                           Only price-valid survivors that are Amazon-verified
     *                           are recorded; the rest are dropped by the record
     *                           gate below.
     */
    public function __construct(
        private readonly KeepaDiscovery $discovery,
        private readonly PreFilter $preFilter,
        private readonly AlreadyPostedGuard $alreadyPostedGuard,
        private readonly CreatorsClient $creators,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly int $pagesPerCycle = 2,
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
        $cycleRun = new CycleRun($startedAt);

        $rawCount = 0;
        $survivingCount = 0;
        $snapshottedCount = 0;
        $snapshotRows = [];
        $seenAsins = [];
        $pagesFetched = 0;
        $reachedEndOfFeed = false;

        // Resume paging where the last Cycle stopped, so cycles walk deeper across
        // runs instead of re-scanning page 0 every time.
        $lastRun = $this->entityManager->getRepository(CycleRun::class)->findLatest();
        $startPage = $lastRun?->getNextStartPage() ?? 0;

        // Sweep a fixed batch of Keepa `/deal` pages from the resume point. The
        // CycleRun funnel counts accumulate across the pages we searched.
        for ($i = 0; $i < $this->pagesPerCycle; ++$i) {
            $page = $startPage + $i;
            // 2. Discover one page of raw candidates.
            $dealPage = $this->discovery->fetchDealPage($page);
            ++$pagesFetched;
            $rawCandidates = $dealPage->candidates;
            $io->writeln(
                \sprintf('<info>Discover:</info> page %d — %d raw candidates from Keepa.', $page, \count($rawCandidates)),
                OutputInterface::VERBOSITY_VERBOSE,
            );
            if ([] === $rawCandidates) {
                $reachedEndOfFeed = true;
                break; // ran off the end of the feed; no deeper pages exist
            }
            $rawCount += \count($rawCandidates);

            // 3. Pre-filter, then Already-Posted Guard -> surviving candidates.
            $preFilterResult = $this->preFilter->apply(...$rawCandidates);
            $this->reportPreFilter($io, \count($rawCandidates), $preFilterResult);
            $guarded = $this->alreadyPostedGuard->apply(...$preFilterResult->survivors)->survivors;

            // Drop ASINs already snapshotted earlier this Cycle (pages can repeat
            // an ASIN); a survivor is processed at most once per Cycle.
            $survivors = [];
            foreach ($guarded as $candidate) {
                if (!isset($seenAsins[$candidate->asin])) {
                    $survivors[] = $candidate;
                }
            }
            $survivingCount += \count($survivors);
            $io->writeln(
                \sprintf(
                    '<info>Already-Posted Guard:</info> page %d — %d -> %d new survivors.',
                    $page,
                    \count($preFilterResult->survivors),
                    \count($survivors),
                ),
                OutputInterface::VERBOSITY_VERBOSE,
            );

            // 4. Live Snapshot for this page's surviving ASINs. A survivor absent
            //    from the map is skipped; a snapshot confirms Price Validity but
            //    only an Amazon-verified one (dealDetails / WAS_PRICE) is recorded.
            $asins = array_map(static fn (Candidate $c): string => $c->asin, $survivors);
            $snapshots = [] === $asins ? [] : $this->creators->fetchSnapshots(...$asins);
            $io->writeln(
                \sprintf('<info>Live Snapshot:</info> page %d — %d ASINs sent, %d returned.', $page, \count($asins), \count($snapshots)),
                OutputInterface::VERBOSITY_VERBOSE,
            );

            // 5. Record one FoundDeal per Amazon-verified survivor. A snapshot
            //    confirms Price Validity; the verification gate below then drops
            //    any survivor that is not Amazon-verified (dealDetails / WAS_PRICE)
            //    so it is never recorded, shown, or published.
            foreach ($survivors as $candidate) {
                $seenAsins[$candidate->asin] = true;
                $snapshot = $snapshots[$candidate->asin] ?? null;
                if (null === $snapshot) {
                    continue;
                }
                ++$snapshottedCount;

                // Verification gate: only Amazon-verified deals are recorded.
                // Unverified snapshots are dropped here and never reach the
                // Review page, the verbose table, or publish.
                if (!$snapshot->isAmazonVerified()) {
                    continue;
                }

                $cycleRun->addFoundDeal(FoundDeal::fromSnapshot($candidate, $snapshot, $startedAt));

                $snapshotRows[] = [
                    $snapshot->asin,
                    $this->euros($snapshot->priceCents),
                    $snapshot->availability ?? '—',
                    $snapshot->savingBasisType ?? '—',
                    $snapshot->hasDealDetails ? 'yes' : 'no',
                    // Always verified here: the gate above dropped the rest.
                    '<info>✓ verified</info>',
                ];
            }

            // Stop before a page we cannot fund. The live meter rode in on this
            // response; on a cache hit it is stale, but $pagesPerCycle still bounds us.
            if ($dealPage->meter->tokensLeft < self::KEEPA_PAGE_COST) {
                $io->writeln(
                    \sprintf('<info>Keepa budget:</info> %d tokens left (< %d) — stopping pagination.', $dealPage->meter->tokensLeft, self::KEEPA_PAGE_COST),
                    OutputInterface::VERBOSITY_VERBOSE,
                );
                break;
            }
        }

        // Every recorded deal is Amazon-verified by construction (the gate in the
        // record loop drops the rest), so recorded count == verified count.
        $foundCount = \count($cycleRun->getFoundDeals());
        $this->reportSnapshots($io, $snapshotRows);

        $cycleRun->setRawCount($rawCount);
        $cycleRun->setSurvivingCount($survivingCount);
        $cycleRun->setSnapshottedCount($snapshottedCount);
        // Advance the cross-run cursor by the pages actually fetched (so a
        // budget-truncated sweep resumes after its last page, not after a page it
        // never reached); reset to 0 when this Cycle ran off the end of the feed.
        $cycleRun->setNextStartPage($reachedEndOfFeed ? 0 : $startPage + $pagesFetched);
        $cycleRun->finish(new \DateTimeImmutable());

        $this->entityManager->persist($cycleRun);
        $this->entityManager->flush();

        $io->success(\sprintf(
            'Cycle complete: %d page(s) searched — %d raw, %d surviving, %d snapshotted, %d Amazon-verified deals recorded.',
            $pagesFetched,
            $rawCount,
            $survivingCount,
            $snapshottedCount,
            $foundCount,
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
     * Verbose-only: one row per recorded deal showing the verification signals
     * (savingBasis + dealDetails). Only Amazon-verified survivors reach this
     * table — unverified snapshots are dropped by the gate in the record loop
     * before a row is built, so they never appear here or in the DB.
     *
     * @param list<array{string, string, string, string, string, string}> $rows
     */
    private function reportSnapshots(SymfonyStyle $io, array $rows): void
    {
        if (!$io->isVerbose() || [] === $rows) {
            return;
        }

        $io->table(
            ['ASIN', 'Price', 'Availability', 'SavingBasis', 'DealDetails', 'Verified'],
            $rows,
        );
    }

    private function euros(int $cents): string
    {
        return \sprintf('%.2f €', $cents / 100);
    }
}
