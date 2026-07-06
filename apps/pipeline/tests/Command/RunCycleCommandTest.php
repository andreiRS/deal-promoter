<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunCycleCommand;
use App\Entity\CycleRun;
use DealPromoter\Shared\AlreadyPosted\AlreadyPostedGuard;
use DealPromoter\Shared\Creators\CreatorsClient;
use DealPromoter\Shared\Creators\LiveSnapshot;
use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Keepa\DealPage;
use DealPromoter\Shared\Keepa\KeepaClient;
use DealPromoter\Shared\Keepa\KeepaDiscovery;
use DealPromoter\Shared\Keepa\TokenMeter;
use DealPromoter\Shared\PreFilter\Criteria;
use DealPromoter\Shared\PreFilter\GuardThresholds;
use DealPromoter\Shared\PreFilter\PreFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;

/**
 * Integration test for the `app:run-cycle` Cycle: a fixture-backed happy path,
 * the run-lock rejection, and the fail-safe (no partial Cycle on dependency
 * error). Real EntityManager + LockFactory + PreFilter + AlreadyPostedGuard come
 * from the container; fakes stand in for KeepaDiscovery and CreatorsClient.
 *
 * Runs inside a rolled-back transaction so it is deterministic and self-cleaning
 * (mirrors CycleRunPersistenceTest). Requires Postgres up + migrated test schema.
 */
final class RunCycleCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em->clear();
        parent::tearDown();
    }

    /**
     * Parse the real ~150-deal fixture into raw Candidates via the real parser.
     *
     * @return list<Candidate>
     */
    private function fixtureCandidates(): array
    {
        $body = file_get_contents(
            __DIR__.'/../../../../packages/shared/tests/fixtures/keepa/deal-page.json',
        );
        self::assertIsString($body);

        $client = new KeepaClient(new MockHttpClient(new MockResponse($body)), 'test-key', 3);

        return $client->fetchDealPage()->candidates;
    }

    private function command(
        KeepaDiscovery $discovery,
        CreatorsClient $creators,
        ?PreFilter $preFilter = null,
    ): CommandTester {
        $container = self::getContainer();

        if (null === $preFilter) {
            $preFilter = $container->get(PreFilter::class);
        }
        self::assertInstanceOf(PreFilter::class, $preFilter);
        $guard = $container->get(AlreadyPostedGuard::class);
        self::assertInstanceOf(AlreadyPostedGuard::class, $guard);
        $lockFactory = $container->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $lockFactory);

        $command = new RunCycleCommand(
            $discovery,
            $preFilter,
            $guard,
            $creators,
            $this->em,
            $lockFactory,
        );

        return new CommandTester($command);
    }

    private function countCycleRuns(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT count(*) FROM cycle_run');

        return (int) $count;
    }

    public function testHappyPathPersistsOneCycleRunWithFoundDeals(): void
    {
        $candidates = $this->fixtureCandidates();

        // Discover the real fixture; the Pre-filter + Guard run for real.
        $discovery = new FakeKeepaDiscovery([new DealPage($candidates, new TokenMeter(60, 5, 5, 0))]);

        // Pick two known surviving ASINs (golden set) and return snapshots for them
        // only — a third surviving ASIN with no snapshot must be skipped silently.
        // A is Amazon-attested (dealDetails + WAS_PRICE); B is a valid snapshot with
        // NO attestation. Both are price-valid survivors and both are snapshotted,
        // but the attestation gate records only A — B is dropped.
        $snapshotA = new LiveSnapshot(
            asin: '019085894X',
            priceCents: 1234,
            availability: 'IN_STOCK',
            condition: 'New',
            merchantId: 'AMZN-DE',
            savingsPercent: 31,
            savingBasisType: 'WAS_PRICE',
            hasDealDetails: true,
            violatesMap: false,
            detailPageUrl: 'https://www.amazon.de/dp/019085894X?tag=mytag-21&linkCode=ogi',
        );
        $snapshotB = new LiveSnapshot(
            asin: 'B0010AH4BW',
            priceCents: 5678,
            availability: 'IN_STOCK',
            condition: 'New',
            merchantId: 'THIRDPARTY',
            savingsPercent: null,
            savingBasisType: null,
            hasDealDetails: false,
            violatesMap: null,
            detailPageUrl: 'https://www.amazon.de/dp/B0010AH4BW?tag=mytag-21&linkCode=ogi',
        );
        $creators = new FakeCreatorsClient([
            '019085894X' => $snapshotA,
            'B0010AH4BW' => $snapshotB,
        ]);

        $tester = $this->command($discovery, $creators);
        $exit = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, $this->countCycleRuns());

        // Verbose output shows the recorded (attested) deal A only. The
        // unattested survivor B is dropped by the gate, so it never appears in
        // the snapshot table.
        $display = $tester->getDisplay();
        self::assertStringContainsString('Live Snapshot:', $display);
        self::assertStringContainsString('019085894X', $display);
        self::assertStringContainsString('attested', $display);
        self::assertStringNotContainsString('B0010AH4BW', $display);

        // Reload the single CycleRun from the DB.
        $cycleRun = $this->em->getRepository(CycleRun::class)->findOneBy([]);
        self::assertInstanceOf(CycleRun::class, $cycleRun);

        self::assertSame(150, $cycleRun->getRawCount());
        // 93 golden survivors clear Pre-filter + (empty) Already-Posted Guard.
        self::assertSame(93, $cycleRun->getSurvivingCount());
        // Both survivors were snapshotted (Price Validity); the funnel counts
        // both even though only the attested one is recorded.
        self::assertSame(2, $cycleRun->getSnapshottedCount());
        self::assertNotNull($cycleRun->getFinishedAt());

        $deals = $cycleRun->getFoundDeals();
        // Only the Amazon-attested survivor A is recorded; unattested B is dropped.
        self::assertCount(1, $deals);

        $byAsin = [];
        foreach ($deals as $deal) {
            $byAsin[$deal->getAsin()] = $deal;
        }
        self::assertArrayHasKey('019085894X', $byAsin);
        self::assertArrayNotHasKey('B0010AH4BW', $byAsin);
        self::assertTrue($byAsin['019085894X']->hasAmazonAttestation());

        $a = $byAsin['019085894X'];
        // Money is integer cents from the snapshot.
        self::assertSame(1234, $a->getSnapshotPriceCents());
        self::assertIsInt($a->getSnapshotPriceCents());
        self::assertSame('IN_STOCK', $a->getAvailability());
        self::assertSame('New', $a->getCondition());
        self::assertSame('AMZN-DE', $a->getMerchantId());
        self::assertSame(31, $a->getAmazonSavingsPct());
        self::assertSame('WAS_PRICE', $a->getSavingBasisType());
        self::assertTrue($a->getHasDealDetails());
        self::assertFalse($a->getViolatesMap());
        // AffiliateUrl carries the verbatim detailPageUrl.
        self::assertSame(
            'https://www.amazon.de/dp/019085894X?tag=mytag-21&linkCode=ogi',
            $a->getAffiliateUrl(),
        );
        // Keepa signals come from the Candidate, not the snapshot.
        $source = null;
        foreach ($candidates as $candidate) {
            if ('019085894X' === $candidate->asin) {
                $source = $candidate;
                break;
            }
        }
        self::assertInstanceOf(Candidate::class, $source);
        self::assertSame($source->avg90Cents, $a->getKeepaAvg90Cents());
        self::assertSame($source->dropPercent90, $a->getKeepaDropPct());
        self::assertSame($source->imageUrl, $a->getImageUrl());

        // No verdict/publish field exists on the entity (raw signals only).
        self::assertFalse(method_exists($a, 'getVerdict'));
        self::assertFalse(method_exists($a, 'isPublished'));
        self::assertFalse(property_exists($a, 'verdict'));
    }

    public function testPaginatesAcrossPagesAndRecordsAttestedSurvivors(): void
    {
        // One survivor per page; the per-page yield is below the target, so the
        // Cycle walks from page 0 to page 1. Page 0's survivor is unattested and
        // page 1's is attested — the gate drops page 0 and records page 1, which
        // also proves the walk reached page 1.
        $discovery = new FakeKeepaDiscovery([
            new DealPage([$this->passingCandidate('PAGE0AAAAA')], new TokenMeter(60, 5, 5, 0)),
            new DealPage([$this->passingCandidate('PAGE1BBBBB')], new TokenMeter(60, 5, 5, 0)),
        ]);
        $creators = new FakeCreatorsClient([
            'PAGE0AAAAA' => $this->snapshot('PAGE0AAAAA', attested: false),
            'PAGE1BBBBB' => $this->snapshot('PAGE1BBBBB', attested: true),
        ]);

        // A permissive Pre-filter so the synthetic candidates survive regardless
        // of the production Criteria wired in the container.
        $tester = $this->command($discovery, $creators, new PreFilter(new Criteria(), new GuardThresholds()));
        $exit = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(Command::SUCCESS, $exit);

        $cycleRun = $this->em->getRepository(CycleRun::class)->findOneBy([]);
        self::assertInstanceOf(CycleRun::class, $cycleRun);

        // Both pages were searched and both survivors were snapshotted, but only
        // the attested page-1 survivor became a found deal.
        self::assertSame(2, $cycleRun->getRawCount());
        self::assertSame(2, $cycleRun->getSurvivingCount());
        self::assertSame(2, $cycleRun->getSnapshottedCount());

        $byAsin = [];
        foreach ($cycleRun->getFoundDeals() as $deal) {
            $byAsin[$deal->getAsin()] = $deal;
        }
        self::assertSame(['PAGE1BBBBB'], array_keys($byAsin));
        self::assertTrue($byAsin['PAGE1BBBBB']->hasAmazonAttestation());

        // Verbose output shows the walk reached page 1.
        self::assertStringContainsString('page 1', $tester->getDisplay());
    }

    public function testResumesPagingWhereTheLastCycleStopped(): void
    {
        // A feed four pages deep (0..3), empty beyond. pagesPerCycle is 2, so two
        // back-to-back cycles must cover pages 0,1 then 2,3 — not re-scan 0,1.
        $discovery = new RecordingKeepaDiscovery([
            new DealPage([$this->passingCandidate('PAGE00AAAA')], new TokenMeter(60, 5, 5, 0)),
            new DealPage([$this->passingCandidate('PAGE01AAAA')], new TokenMeter(60, 5, 5, 0)),
            new DealPage([$this->passingCandidate('PAGE02AAAA')], new TokenMeter(60, 5, 5, 0)),
            new DealPage([$this->passingCandidate('PAGE03AAAA')], new TokenMeter(60, 5, 5, 0)),
        ]);
        $creators = new FakeCreatorsClient([
            'PAGE00AAAA' => $this->snapshot('PAGE00AAAA', attested: true),
            'PAGE01AAAA' => $this->snapshot('PAGE01AAAA', attested: true),
            'PAGE02AAAA' => $this->snapshot('PAGE02AAAA', attested: true),
            'PAGE03AAAA' => $this->snapshot('PAGE03AAAA', attested: true),
        ]);
        $preFilter = new PreFilter(new Criteria(), new GuardThresholds());

        // Cycle 1: fresh DB, cursor starts at 0, sweeps pages 0 and 1.
        $this->command($discovery, $creators, $preFilter)->execute([]);
        // Cycle 2: reads the cursor left by Cycle 1 and resumes at page 2.
        $this->command($discovery, $creators, $preFilter)->execute([]);

        // Exactly pages 0,1,2,3 were requested, in order — no page re-scanned.
        self::assertSame([0, 1, 2, 3], $discovery->requestedPages);

        $runs = $this->em->getRepository(CycleRun::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $runs);
        // Cycle 1 leaves the cursor at page 2; Cycle 2 advances it to page 4.
        self::assertSame(2, $runs[0]->getNextStartPage());
        self::assertSame(4, $runs[1]->getNextStartPage());
    }

    public function testCursorResetsToZeroWhenTheSweepRunsOffTheEndOfTheFeed(): void
    {
        // Page 0 has deals, page 1 is the end of the feed (empty). The sweep stops
        // there and the cursor wraps back to 0 for the next Cycle.
        $discovery = new RecordingKeepaDiscovery([
            new DealPage([$this->passingCandidate('ENDPAGE0AA')], new TokenMeter(60, 5, 5, 0)),
        ]);
        $creators = new FakeCreatorsClient([
            'ENDPAGE0AA' => $this->snapshot('ENDPAGE0AA', attested: true),
        ]);

        $this->command($discovery, $creators, new PreFilter(new Criteria(), new GuardThresholds()))->execute([]);

        self::assertSame([0, 1], $discovery->requestedPages);

        $run = $this->em->getRepository(CycleRun::class)->findOneBy([]);
        self::assertInstanceOf(CycleRun::class, $run);
        self::assertSame(0, $run->getNextStartPage());
    }

    private function passingCandidate(string $asin): Candidate
    {
        // Values mirror PreFilterTest::passingCandidate — they clear the default
        // Criteria and Outlier Guards.
        return new Candidate(
            asin: $asin,
            title: 'A fine deal',
            imageUrl: '',
            currentPriceCents: 5000,
            avg30Cents: 9000,
            avg90Cents: 10000,
            dropPercent90: 50,
            salesRankDrops90: 5,
            salesRank: 10000,
            ratingStarsTimesTen: 45,
            rootCategory: 281052031,
            categories: [281052031],
        );
    }

    private function snapshot(string $asin, bool $attested): LiveSnapshot
    {
        return new LiveSnapshot(
            asin: $asin,
            priceCents: 4999,
            availability: 'IN_STOCK',
            condition: 'New',
            merchantId: 'AMZN-DE',
            savingsPercent: $attested ? 30 : null,
            savingBasisType: $attested ? 'WAS_PRICE' : 'LIST_PRICE',
            hasDealDetails: $attested,
            violatesMap: false,
            detailPageUrl: \sprintf('https://www.amazon.de/dp/%s?tag=t-21&linkCode=ogi', $asin),
        );
    }

    public function testRunLockRejectsAConcurrentCycle(): void
    {
        $lockFactory = self::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $lockFactory);

        // Hold the run-lock as if another Cycle were already running.
        $held = $lockFactory->createLock('deal-pipeline-cycle');
        self::assertTrue($held->acquire());

        try {
            $discovery = new FakeKeepaDiscovery(
                [new DealPage($this->fixtureCandidates(), new TokenMeter(60, 5, 5, 0))],
            );
            $creators = new FakeCreatorsClient([]);

            $tester = $this->command($discovery, $creators);
            $exit = $tester->execute([]);

            // Rejected cleanly, success exit, and NO new CycleRun.
            self::assertSame(Command::SUCCESS, $exit);
            self::assertSame(0, $this->countCycleRuns());
            self::assertStringContainsString('already running', $tester->getDisplay());
        } finally {
            $held->release();
        }
    }

    public function testCreatorsErrorSkipsCleanlyWithNoPartialCycle(): void
    {
        $discovery = new FakeKeepaDiscovery(
            [new DealPage($this->fixtureCandidates(), new TokenMeter(60, 5, 5, 0))],
        );
        $creators = new ThrowingCreatorsClient();

        $tester = $this->command($discovery, $creators);
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(0, $this->countCycleRuns());
    }

    public function testKeepaErrorSkipsCleanlyWithNoPartialCycle(): void
    {
        $discovery = new ThrowingKeepaDiscovery();
        $creators = new FakeCreatorsClient([]);

        $tester = $this->command($discovery, $creators);
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(0, $this->countCycleRuns());
    }
}

final readonly class FakeKeepaDiscovery implements KeepaDiscovery
{
    /**
     * @param list<DealPage> $pages one DealPage per page index; requests beyond
     *                              the list return an empty page (end of feed)
     */
    public function __construct(private array $pages)
    {
    }

    public function fetchDealPage(int $page = 0): DealPage
    {
        return $this->pages[$page] ?? new DealPage([], new TokenMeter(60, 5, 5, 0));
    }
}

final class RecordingKeepaDiscovery implements KeepaDiscovery
{
    /** @var list<int> the page indices requested, in call order */
    public array $requestedPages = [];

    /**
     * @param list<DealPage> $pages one DealPage per page index; requests beyond
     *                              the list return an empty page (end of feed)
     */
    public function __construct(private readonly array $pages)
    {
    }

    public function fetchDealPage(int $page = 0): DealPage
    {
        $this->requestedPages[] = $page;

        return $this->pages[$page] ?? new DealPage([], new TokenMeter(60, 5, 5, 0));
    }
}

final class ThrowingKeepaDiscovery implements KeepaDiscovery
{
    public function fetchDealPage(int $page = 0): DealPage
    {
        throw new \RuntimeException('Keepa is down');
    }
}

final readonly class FakeCreatorsClient implements CreatorsClient
{
    /**
     * @param array<string, LiveSnapshot> $snapshots
     */
    public function __construct(private array $snapshots)
    {
    }

    public function fetchSnapshots(string ...$asins): array
    {
        $out = [];
        foreach ($asins as $asin) {
            if (isset($this->snapshots[$asin])) {
                $out[$asin] = $this->snapshots[$asin];
            }
        }

        return $out;
    }
}

final class ThrowingCreatorsClient implements CreatorsClient
{
    public function fetchSnapshots(string ...$asins): array
    {
        throw new \RuntimeException('Creators API is down');
    }
}
