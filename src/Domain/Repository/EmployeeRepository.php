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
     * Returns employees filtered by active/inactive status.
     *
     * Active:   leftAt IS NULL OR leftAt > asOf
     * Inactive: leftAt IS NOT NULL AND leftAt <= asOf
     * All:      no status filter
     *
     * Inactive = exit date has passed or is today, matching the exit-scheduler
     * definition (#81/#82) so the badge and deactivation stay in sync.
     *
     * @param 'active'|'inactive'|'all' $status
     * @return list<Employee>
     */
    public function findByCompanyAndStatus(
        Company $company,
        string $status,
        \DateTimeImmutable $asOf,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->setParameter('company', $company)
            ->orderBy('e.fullName', 'ASC');

        $day = $asOf->setTime(0, 0);

        if ('active' === $status) {
            $qb->andWhere('e.leftAt IS NULL OR e.leftAt > :asOf')->setParameter('asOf', $day);
        } elseif ('inactive' === $status) {
            $qb->andWhere('e.leftAt IS NOT NULL AND e.leftAt <= :asOf')->setParameter('asOf', $day);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Employees that have already been anonymized, scoped to one company.
     *
     * @return list<Employee>
     */
    public function findAlreadyAnonymizedByCompany(Company $company): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->andWhere('e.anonymizedAt IS NOT NULL')
            ->setParameter('company', $company)
            ->orderBy('e.anonymizedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Employees whose DSGVO retention period has elapsed and who have not
     * yet been anonymized. The retention window is computed per company:
     * leftAt + company.retentionPeriodMonths <= asOf.
     *
     * Uses DATE_ADD DQL function so the comparison stays in the DB and
     * does not load the full employee set into memory.
     *
     * @return list<Employee>
     */
    public function findDueForAnonymization(\DateTimeImmutable $asOf): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.company', 'c')
            ->andWhere('e.leftAt IS NOT NULL')
            ->andWhere('e.anonymizedAt IS NULL')
            ->andWhere('DATE_ADD(e.leftAt, c.retentionPeriodMonths, \'MONTH\') <= :asOf')
            ->setParameter('asOf', $asOf)
            ->orderBy('e.leftAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Employees whose exit date has been reached and whose linked user
     * account is still active. Used by the daily exit-deactivation sweep
     * (#81, #82) to handle future-dated exits that were skipped at the
     * time EmployeeExitService ran.
     *
     * Anonymized employees are excluded — their user link is already gone
     * at that point anyway, but the guard keeps the query intent clear.
     *
     * @return list<Employee>
     */
    public function findExitedWithActiveUser(\DateTimeImmutable $asOf): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.user', 'u')
            ->andWhere('e.leftAt IS NOT NULL')
            ->andWhere('e.leftAt <= :asOf')
            ->andWhere('u.active = true')
            ->andWhere('e.anonymizedAt IS NULL')
            ->setParameter('asOf', $asOf)
            ->orderBy('e.leftAt', 'ASC')
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
