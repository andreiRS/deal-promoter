<?php

declare(strict_types=1);

namespace App\Tests\AlreadyPosted;

use App\Entity\PostedDeal;
use DealPromoter\Shared\AlreadyPosted\AlreadyPostedGuard;
use DealPromoter\Shared\AlreadyPosted\NeverRepostPolicy;
use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Storage\RecordedPriceHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test: seeds a `posted_deal` row via the EntityManager, builds the
 * guard with the real {@see DoctrineRecordedPriceHistory} from the container, and
 * asserts the seeded ASIN is suppressed while an unseeded ASIN survives.
 *
 * Runs inside a transaction that is rolled back in tearDown so the test is
 * deterministic and self-cleaning. Pattern mirrors {@see CycleRunPersistenceTest}.
 *
 * Requires Postgres up + migrated test schema (see CycleRunPersistenceTest docs).
 */
final class AlreadyPostedGuardIntegrationTest extends KernelTestCase
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

    private function candidate(string $asin): Candidate
    {
        return new Candidate(
            asin: $asin,
            title: 'Integration Test Product',
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

    public function testSeededAsinIsSuppressedByRealStorage(): void
    {
        // Seed a posted_deal row in the rolled-back transaction.
        $postedDeal = new PostedDeal(
            'B0INTEGRATION1',
            4999,
            new \DateTimeImmutable('2026-06-01T08:00:00+00:00'),
        );
        $this->em->persist($postedDeal);
        $this->em->flush();

        $history = self::getContainer()->get(RecordedPriceHistory::class);
        self::assertInstanceOf(RecordedPriceHistory::class, $history);

        $guard = new AlreadyPostedGuard($history, new NeverRepostPolicy());

        $seeded = $this->candidate('B0INTEGRATION1');
        $fresh = $this->candidate('B0INTEGRATION2');

        $result = $guard->apply($seeded, $fresh);

        // The seeded ASIN is suppressed; the fresh one survives.
        self::assertCount(1, $result->survivors);
        self::assertCount(1, $result->suppressed);
        self::assertSame('B0INTEGRATION2', $result->survivors[0]->asin);
        self::assertSame('B0INTEGRATION1', $result->suppressed[0]->asin);
    }

    public function testEmptyTablePassesEverythingThrough(): void
    {
        // Ensure clean slate within the rolled-back transaction.
        $this->em->getConnection()->executeStatement('DELETE FROM posted_deal');

        $history = self::getContainer()->get(RecordedPriceHistory::class);
        self::assertInstanceOf(RecordedPriceHistory::class, $history);

        $guard = new AlreadyPostedGuard($history, new NeverRepostPolicy());

        $a = $this->candidate('B0FRESH00AA');
        $b = $this->candidate('B0FRESH00BB');

        $result = $guard->apply($a, $b);

        self::assertCount(2, $result->survivors);
        self::assertCount(0, $result->suppressed);
    }
}
