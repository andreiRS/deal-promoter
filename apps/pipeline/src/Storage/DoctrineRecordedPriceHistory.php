<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\PostedDeal;
use DealPromoter\Shared\Storage\RecordedPriceHistory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine-backed Recorded Price History, querying the `posted_deal` table.
 * Consumers depend on the RecordedPriceHistory interface, never on this class.
 */
final readonly class DoctrineRecordedPriceHistory implements RecordedPriceHistory
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function hasPostedAsin(string $asin): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(PostedDeal::class, 'p')
            ->where('p.asin = :asin')
            ->setParameter('asin', $asin)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
