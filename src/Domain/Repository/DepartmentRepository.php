<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Department>
 */
class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /**
     * @return list<Department>
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.active', 'DESC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All active departments where the given employee is either the lead or
     * the deputy. Drives the manager approval list query.
     *
     * @return list<Department>
     */
    public function findActiveWhereEmployeeIsLeadOrDeputy(Employee $employee): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.active = :active')
            ->andWhere('(d.lead = :employee OR d.deputy = :employee)')
            ->setParameter('active', true)
            ->setParameter('employee', $employee)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
