<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CycleRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CycleRun>
 */
class CycleRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CycleRun::class);
    }

    /**
     * The most recent Cycle, by start time (id as deterministic tiebreak).
     */
    public function findLatest(): ?CycleRun
    {
        $result = $this->createQueryBuilder('c')
            ->orderBy('c.startedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert(null === $result || $result instanceof CycleRun);

        return $result;
    }
}
