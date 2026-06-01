<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use DealPromoter\Shared\Storage\RecordedPriceHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the storage seam wires through the container (interface -> Doctrine
 * impl) and queries `posted_deal`. Full Already-Posted Guard behaviour lands in
 * P6; here we only assert the empty-table read returns false.
 *
 * Requires Postgres up + migrated test schema (see CycleRunPersistenceTest).
 */
final class DoctrineRecordedPriceHistoryTest extends KernelTestCase
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
        parent::tearDown();
    }

    public function testHasPostedAsinIsFalseOnEmptyTable(): void
    {
        $history = self::getContainer()->get(RecordedPriceHistory::class);
        self::assertInstanceOf(RecordedPriceHistory::class, $history);

        // Ensure a clean slate within the rolled-back transaction.
        $this->em->getConnection()->executeStatement('DELETE FROM posted_deal');

        self::assertFalse($history->hasPostedAsin('B0CGMFY7MV'));
    }
}
