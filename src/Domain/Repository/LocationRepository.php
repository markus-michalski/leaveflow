<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\Location;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /**
     * @return list<Location>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.company = :company')
            ->setParameter('company', $company)
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
