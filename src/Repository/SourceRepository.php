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
     * pessimistic write lock (SELECT ... FOR UPDATE).
     *
     * Enforces a minimum 200 ms cooldown between consecutive fetches of the
     * same source
     */
    public function acquireNext(): ?Source
    {
        $now = new \DateTimeImmutable();
        $cooldownCutoff = $now->modify('-200 milliseconds');

        return $this->createQueryBuilder('s')
            ->where('s.lockedUntil IS NULL OR s.lockedUntil < :now')
            ->andWhere('s.lastFetchedAt IS NULL OR s.lastFetchedAt < :cooldownCutoff')
            ->setParameter('now', $now)
            ->setParameter('cooldownCutoff', $cooldownCutoff)
            ->orderBy('s.lastFetchedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
