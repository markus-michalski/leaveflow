<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyHoliday>
 */
class CompanyHolidayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyHoliday::class);
    }

    /**
     * @return list<CompanyHoliday>
     */
    public function findByCompanyAndYear(Company $company, int $year): array
    {
        $start = new \DateTimeImmutable()->setDate($year, 1, 1)->setTime(0, 0);
        $end = new \DateTimeImmutable()->setDate($year, 12, 31)->setTime(23, 59, 59);

        return $this->createQueryBuilder('h')
            ->andWhere('h.company = :company')
            ->andWhere('h.date BETWEEN :start AND :end')
            ->setParameter('company', $company)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('h.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CompanyHoliday>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.company = :company')
            ->setParameter('company', $company)
            ->orderBy('h.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
