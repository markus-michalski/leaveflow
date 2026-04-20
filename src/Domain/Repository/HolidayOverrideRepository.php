<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\HolidayOverride;
use App\Domain\Enum\FederalState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HolidayOverride>
 */
class HolidayOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HolidayOverride::class);
    }

    /**
     * @return list<HolidayOverride>
     */
    public function findByCompanyYearAndState(Company $company, int $year, FederalState $state): array
    {
        $start = (new \DateTimeImmutable())->setDate($year, 1, 1)->setTime(0, 0);
        $end = (new \DateTimeImmutable())->setDate($year, 12, 31)->setTime(23, 59, 59);

        return $this->createQueryBuilder('o')
            ->andWhere('o.company = :company')
            ->andWhere('o.federalState = :state')
            ->andWhere('o.date BETWEEN :start AND :end')
            ->setParameter('company', $company)
            ->setParameter('state', $state)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('o.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<HolidayOverride>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.company = :company')
            ->setParameter('company', $company)
            ->orderBy('o.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
