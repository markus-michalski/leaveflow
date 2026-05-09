<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Employee>
 */
class EmployeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employee::class);
    }

    public function findOneByEmployeeNumber(Company $company, string $employeeNumber): ?Employee
    {
        return $this->findOneBy([
            'company' => $company,
            'employeeNumber' => trim($employeeNumber),
        ]);
    }

    public function findOneByUser(User $user): ?Employee
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * @return list<Employee>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->setParameter('company', $company)
            ->orderBy('e.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active employees (joinedAt has passed, no leftAt or leftAt in the
     * future). Drives cross-company sweeps such as the 6-week illness
     * alert.
     *
     * @return list<Employee>
     */
    public function findAllActive(\DateTimeImmutable $asOf): array
    {
        $asOf = $asOf->setTime(0, 0);

        return $this->createQueryBuilder('e')
            ->andWhere('e.joinedAt <= :asOf')
            ->andWhere('e.leftAt IS NULL OR e.leftAt >= :asOf')
            ->setParameter('asOf', $asOf)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
