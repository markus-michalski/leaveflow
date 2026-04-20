<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Enum\LeaveEntitlementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveEntitlement>
 */
class LeaveEntitlementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveEntitlement::class);
    }

    /**
     * @return list<LeaveEntitlement>
     */
    public function findByEmployeeAndYear(Employee $employee, int $year): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.employee = :employee')
            ->andWhere('e.year = :year')
            ->setParameter('employee', $employee)
            ->setParameter('year', $year)
            ->orderBy('e.expiresAt', 'ASC')
            ->addOrderBy('e.type', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByEmployeeYearAndType(Employee $employee, int $year, LeaveEntitlementType $type): ?LeaveEntitlement
    {
        return $this->findOneBy([
            'employee' => $employee,
            'year' => $year,
            'type' => $type,
        ]);
    }

    /**
     * @return list<LeaveEntitlement>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.employee', 'emp')
            ->andWhere('emp.company = :company')
            ->setParameter('company', $company)
            ->orderBy('e.year', 'DESC')
            ->addOrderBy('emp.fullName', 'ASC')
            ->addOrderBy('e.type', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
