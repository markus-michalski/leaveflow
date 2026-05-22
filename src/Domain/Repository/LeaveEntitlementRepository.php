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
        return $this->findByCompanyAndYear($company, null);
    }

    /**
     * Year-scoped variant of {@see findAllByCompany}. Pass null to include
     * every year. Used by the admin entitlement list to default to the
     * current year (typical SMB doesn't want to scroll past three years
     * of history every time they open the page).
     *
     * @return list<LeaveEntitlement>
     */
    public function findByCompanyAndYear(Company $company, ?int $year): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.employee', 'emp')
            ->andWhere('emp.company = :company')
            ->setParameter('company', $company)
            ->orderBy('e.year', 'DESC')
            ->addOrderBy('emp.fullName', 'ASC')
            ->addOrderBy('e.type', 'ASC');

        if (null !== $year) {
            $qb->andWhere('e.year = :year')->setParameter('year', $year);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Distinct years that have entitlement records for this company,
     * ordered newest-first. Drives the year-filter dropdown.
     *
     * @return list<int>
     */
    public function findAvailableYears(Company $company): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.year')
            ->join('e.employee', 'emp')
            ->andWhere('emp.company = :company')
            ->setParameter('company', $company)
            ->orderBy('e.year', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): int => (int) $row['year'], $rows));
    }

    /**
     * Carryovers in a company that expire within the next N days and still
     * have hours left. Drives the "Verfalls-Risiko"-action on the admin
     * statistics dashboard. Unlike {@see findExpiringWithoutWarning} this
     * does NOT consider the warning-sent-at flag — the dashboard always
     * shows the current at-risk set so it can serve as a planning tool, not
     * just a one-shot notification trigger.
     *
     * @return list<LeaveEntitlement>
     */
    public function findCarryoversExpiringWithin(
        Company $company,
        \DateTimeImmutable $today,
        int $daysAhead,
    ): array {
        $today = $today->setTime(0, 0);
        $threshold = $today->modify(\sprintf('+%d days', $daysAhead));

        return $this->createQueryBuilder('e')
            ->join('e.employee', 'emp')
            ->andWhere('emp.company = :company')
            ->andWhere('e.expiresAt IS NOT NULL')
            ->andWhere('e.expiresAt >= :today')
            ->andWhere('e.expiresAt <= :threshold')
            ->andWhere('(e.hoursGranted - e.hoursUsed) > 0')
            ->setParameter('company', $company)
            ->setParameter('today', $today)
            ->setParameter('threshold', $threshold)
            ->orderBy('e.expiresAt', 'ASC')
            ->addOrderBy('emp.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all Carryover entitlements granted before `$beforeYear` that
     * have not yet expired at `$asOf`. Covers carryovers stored under any
     * prior year — not just year-1 — so illness-extended or contractually
     * prolonged carryovers (beyond the standard BUrlG 15-month window) are
     * included without changing the caller.
     *
     * @return list<LeaveEntitlement>
     */
    public function findUnexpiredCarryoversByEmployeeBeforeYear(
        Employee $employee,
        int $beforeYear,
        \DateTimeImmutable $asOf,
    ): array {
        return $this->createQueryBuilder('e')
            ->andWhere('e.employee = :employee')
            ->andWhere('e.year < :beforeYear')
            ->andWhere('e.type = :type')
            ->andWhere('e.expiresAt >= :asOf')
            ->setParameter('employee', $employee)
            ->setParameter('beforeYear', $beforeYear)
            ->setParameter('type', LeaveEntitlementType::Carryover)
            ->setParameter('asOf', $asOf->setTime(0, 0))
            ->orderBy('e.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Drives the EntitlementExpiringSoon scheduler. Returns entitlements
     * whose expiresAt falls within [today, today+daysAhead] AND have not yet
     * triggered an expiry warning AND still have hours remaining (no point
     * warning about a fully-consumed carryover).
     *
     * @return list<LeaveEntitlement>
     */
    public function findExpiringWithoutWarning(\DateTimeImmutable $today, int $daysAhead = 30): array
    {
        $threshold = $today->setTime(0, 0)->modify(\sprintf('+%d days', $daysAhead));

        return $this->createQueryBuilder('e')
            ->andWhere('e.expiresAt IS NOT NULL')
            ->andWhere('e.expiresAt >= :today')
            ->andWhere('e.expiresAt <= :threshold')
            ->andWhere('e.expiryWarningSentAt IS NULL')
            ->andWhere('(e.hoursGranted - e.hoursUsed) > 0')
            ->setParameter('today', $today->setTime(0, 0))
            ->setParameter('threshold', $threshold)
            ->orderBy('e.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
