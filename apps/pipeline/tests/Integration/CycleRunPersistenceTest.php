<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CycleRun;
use App\Entity\FoundDeal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the kernel (proving the DI container compiles) and round-trips a
 * CycleRun with FoundDeal rows through Postgres. Runs inside a transaction that
 * is rolled back in tearDown, so it is deterministic and self-cleaning.
 *
 * Requires Postgres up and the test schema migrated, e.g.:
 *   docker compose run --rm app sh -lc '\
 *     APP_ENV=test php bin/console doctrine:database:create --if-not-exists && \
 *     APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction && \
 *     vendor/bin/phpunit --testsuite app'
 */
final class CycleRunPersistenceTest extends KernelTestCase
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

    public function testPersistsAndReadsBackCycleRunWithFoundDeals(): void
    {
        $startedAt = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');
        $cycle = new CycleRun($startedAt);
        $cycle->setRawCount(150);
        $cycle->setSurvivingCount(12);
        $cycle->setSnapshottedCount(3);

        $dealA = new FoundDeal('B0CGMFY7MV', 'Echo Dot (5th Gen)', $startedAt);
        $dealA->setKeepaAvg90Cents(5999);
        $dealA->setKeepaDropPct(34);
        $dealA->setSnapshotPriceCents(3999);
        $cycle->addFoundDeal($dealA);

        $dealB = new FoundDeal('B07PGL2N7J', 'Fire TV Stick', $startedAt);
        $cycle->addFoundDeal($dealB);

        $this->em->persist($cycle);
        $this->em->flush();

        $cycleId = $cycle->getId();
        self::assertNotNull($cycleId);

        // Read back from the DB, not the identity map.
        $this->em->clear();

        $reloaded = $this->em->find(CycleRun::class, $cycleId);
        self::assertInstanceOf(CycleRun::class, $reloaded);
        self::assertSame(150, $reloaded->getRawCount());
        self::assertSame(12, $reloaded->getSurvivingCount());
        self::assertSame(3, $reloaded->getSnapshottedCount());
        self::assertEquals($startedAt, $reloaded->getStartedAt());
        self::assertNull($reloaded->getFinishedAt());

        $deals = $reloaded->getFoundDeals();
        self::assertCount(2, $deals);

        $byAsin = [];
        foreach ($deals as $deal) {
            $byAsin[$deal->getAsin()] = $deal;
        }

        $a = $byAsin['B0CGMFY7MV'];
        self::assertSame('Echo Dot (5th Gen)', $a->getTitle());
        // Money stays integer cents through the round-trip.
        self::assertSame(5999, $a->getKeepaAvg90Cents());
        self::assertSame(34, $a->getKeepaDropPct());
        self::assertSame(3999, $a->getSnapshotPriceCents());
        self::assertIsInt($a->getSnapshotPriceCents());

        $b = $byAsin['B07PGL2N7J'];
        self::assertSame('Fire TV Stick', $b->getTitle());
        // Snapshot columns are nullable and unset here.
        self::assertNull($b->getSnapshotPriceCents());
        self::assertNull($b->getKeepaAvg90Cents());
    }
}
