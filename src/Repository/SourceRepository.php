<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Source;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

class SourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Source::class);
    }

    /**
     * Selects the next available (unlocked) source for processing using a
     * pessimistic write lock (SELECT ... FOR UPDATE). Must be called inside
     * an active transaction so the lock is held until the caller commits.
     */
    public function acquireNext(): ?Source
    {
        return $this->createQueryBuilder('s')
            ->where('s.lockedUntil IS NULL OR s.lockedUntil < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.lastFetchedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
