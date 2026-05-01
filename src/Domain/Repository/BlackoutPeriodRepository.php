<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlackoutPeriod>
 */
class BlackoutPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlackoutPeriod::class);
    }

    /**
     * Returns all blackouts in a company, ordered by start date ascending.
     *
     * @return list<BlackoutPeriod>
     */
    public function findAllForCompany(Company $company): array
    {
        /** @var list<BlackoutPeriod> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.company = :company')
            ->setParameter('company', $company)
            ->orderBy('b.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Returns blackouts whose date range overlaps the given range and whose
     * scope (company-wide OR matching department) covers the given department.
     *
     * Two ranges overlap when start_a <= end_b AND end_a >= start_b.
     *
     * @return list<BlackoutPeriod>
     */
    public function findOverlapping(
        Company $company,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        ?Department $department = null,
    ): array {
        $qb = $this->createQueryBuilder('b')
            ->where('b.company = :company')
            ->andWhere('b.startDate <= :rangeEnd')
            ->andWhere('b.endDate >= :rangeStart')
            ->setParameter('company', $company)
            ->setParameter('rangeStart', $rangeStart->setTime(0, 0))
            ->setParameter('rangeEnd', $rangeEnd->setTime(0, 0))
            ->orderBy('b.startDate', 'ASC');

        if (null === $department) {
            // No department context: only company-wide blackouts apply.
            $qb->andWhere('b.department IS NULL');
        } else {
            $qb->andWhere('b.department IS NULL OR b.department = :department')
                ->setParameter('department', $department);
        }

        /** @var list<BlackoutPeriod> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
