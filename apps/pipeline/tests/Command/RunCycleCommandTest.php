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
use DealPromoter\Shared\PreFilter\PreFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
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

    private function command(KeepaDiscovery $discovery, CreatorsClient $creators): CommandTester
    {
        $container = self::getContainer();

        $preFilter = $container->get(PreFilter::class);
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
        $discovery = new FakeKeepaDiscovery(new DealPage($candidates, new TokenMeter(60, 5, 5, 0)));

        // Pick two known surviving ASINs (golden set) and return snapshots for them
        // only — a third surviving ASIN with no snapshot must be skipped silently.
        // A is Amazon-attested (dealDetails + WAS_PRICE) and becomes a found deal;
        // B is a valid snapshot with NO attestation, so the Strict dial counts it
        // as snapshotted but does not record it as a deal.
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
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, $this->countCycleRuns());

        // Reload the single CycleRun from the DB.
        $cycleRun = $this->em->getRepository(CycleRun::class)->findOneBy([]);
        self::assertInstanceOf(CycleRun::class, $cycleRun);

        self::assertSame(150, $cycleRun->getRawCount());
        // 93 golden survivors clear Pre-filter + (empty) Already-Posted Guard.
        self::assertSame(93, $cycleRun->getSurvivingCount());
        // Both survivors were snapshotted (Price Validity), but only the attested
        // one becomes a found deal.
        self::assertSame(2, $cycleRun->getSnapshottedCount());
        self::assertNotNull($cycleRun->getFinishedAt());

        $deals = $cycleRun->getFoundDeals();
        self::assertCount(1, $deals);

        $byAsin = [];
        foreach ($deals as $deal) {
            $byAsin[$deal->getAsin()] = $deal;
        }
        // A is attested → recorded; B is unattested → snapshotted but not recorded.
        self::assertArrayHasKey('019085894X', $byAsin);
        self::assertArrayNotHasKey('B0010AH4BW', $byAsin);

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

    public function testRunLockRejectsAConcurrentCycle(): void
    {
        $lockFactory = self::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $lockFactory);

        // Hold the run-lock as if another Cycle were already running.
        $held = $lockFactory->createLock('deal-pipeline-cycle');
        self::assertTrue($held->acquire());

        try {
            $discovery = new FakeKeepaDiscovery(
                new DealPage($this->fixtureCandidates(), new TokenMeter(60, 5, 5, 0)),
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
            new DealPage($this->fixtureCandidates(), new TokenMeter(60, 5, 5, 0)),
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
    public function __construct(private DealPage $page)
    {
    }

    public function fetchDealPage(int $page = 0): DealPage
    {
        return $this->page;
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
