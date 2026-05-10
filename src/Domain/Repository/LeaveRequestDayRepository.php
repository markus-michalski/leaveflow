<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequestDay;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequestDay>
 */
class LeaveRequestDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequestDay::class);
    }

    /**
     * Sum per calendar year of non-excluded days attached to pending leave
     * requests for an employee. Used by LeaveRequestService to guard against
     * oversubscription when checking the employee's remaining balance.
     *
     * PHP-side aggregation on purpose: DQL doesn't ship YEAR() without a
     * platform-specific extension, and the row count per employee/pending
     * stays bounded (a handful of requests, each holding a few dozen days at
     * most), so portability beats pushing the grouping into SQL.
     *
     * @return array<int, float> keyed by year, e.g. [2025 => 40.0, 2026 => 8.0]
     */
    public function sumPendingHoursByYear(Employee $employee): array
    {
        /** @var list<array{date: \DateTimeImmutable, hours: float}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.date AS date', 'd.hours AS hours')
            ->innerJoin('d.leaveRequest', 'r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.status = :status')
            ->andWhere('d.status != :excluded')
            ->setParameter('employee', $employee)
            ->setParameter('status', LeaveRequestStatus::Pending->value)
            ->setParameter('excluded', LeaveDayStatus::Excluded->value)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $year = (int) $row['date']->format('Y');
            $result[$year] = ($result[$year] ?? 0.0) + (float) $row['hours'];
        }

        return $result;
    }

    /**
     * Sum of illness-tracked hours per employee for a company in the given
     * range. Drives the IllnessRateCalculator on the admin statistics
     * dashboard.
     *
     * Excluded days carry zero hours by invariant; we still scope the query
     * by status to keep the SUM deterministic if that ever changes.
     * Both Approved and Recorded are counted — Recorded is the default for
     * Krankheit since eAU made approval moot.
     *
     * @return array<int, float> employeeId => hours
     */
    public function sumIllnessHoursByEmployeeForCompany(
        Company $company,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        /** @var list<array{employeeId: int, hours: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(r.employee) AS employeeId', 'SUM(d.hours) AS hours')
            ->innerJoin('d.leaveRequest', 'r')
            ->innerJoin('r.employee', 'e')
            ->innerJoin('r.absenceType', 't')
            ->andWhere('e.company = :company')
            ->andWhere('t.illnessTracking = true')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('d.status != :excluded')
            ->andWhere('d.date >= :rangeStart')
            ->andWhere('d.date <= :rangeEnd')
            ->setParameter('company', $company)
            ->setParameter('statuses', [
                LeaveRequestStatus::Approved->value,
                LeaveRequestStatus::Recorded->value,
            ])
            ->setParameter('excluded', LeaveDayStatus::Excluded->value)
            ->setParameter('rangeStart', $rangeStart->setTime(0, 0))
            ->setParameter('rangeEnd', $rangeEnd->setTime(0, 0))
            ->groupBy('r.employee')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['employeeId']] = (float) $row['hours'];
        }

        return $result;
    }

    /**
     * Sum of approved, leave-deducting hours per calendar month for a
     * company in the given year. Drives the monthly-distribution chart on
     * the admin statistics dashboard.
     *
     * PHP-side bucketing on purpose: same DQL-MONTH portability constraint
     * as {@see sumPendingHoursByYear}. Volume is bounded by year × company
     * so the array hydration cost is negligible.
     *
     * @return array<int, float> month (1..12) => hours; missing months
     *                          mean zero so the caller fills with 0.0
     */
    public function sumApprovedDeductingHoursByMonth(Company $company, int $year): array
    {
        $rangeStart = (new \DateTimeImmutable())->setDate($year, 1, 1)->setTime(0, 0);
        $rangeEnd = (new \DateTimeImmutable())->setDate($year, 12, 31)->setTime(0, 0);

        /** @var list<array{date: \DateTimeImmutable, hours: float}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.date AS date', 'd.hours AS hours')
            ->innerJoin('d.leaveRequest', 'r')
            ->innerJoin('r.employee', 'e')
            ->innerJoin('r.absenceType', 't')
            ->andWhere('e.company = :company')
            ->andWhere('t.deductsFromLeave = true')
            ->andWhere('r.status = :approved')
            ->andWhere('d.status != :excluded')
            ->andWhere('d.date >= :rangeStart')
            ->andWhere('d.date <= :rangeEnd')
            ->setParameter('company', $company)
            ->setParameter('approved', LeaveRequestStatus::Approved->value)
            ->setParameter('excluded', LeaveDayStatus::Excluded->value)
            ->setParameter('rangeStart', $rangeStart)
            ->setParameter('rangeEnd', $rangeEnd)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $month = (int) $row['date']->format('n');
            $result[$month] = ($result[$month] ?? 0.0) + (float) $row['hours'];
        }

        return $result;
    }
}
