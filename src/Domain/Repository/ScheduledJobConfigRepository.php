<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\ScheduledJobConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledJobConfig>
 */
class ScheduledJobConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledJobConfig::class);
    }

    public function findOneByName(string $name): ?ScheduledJobConfig
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * @return list<ScheduledJobConfig>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
