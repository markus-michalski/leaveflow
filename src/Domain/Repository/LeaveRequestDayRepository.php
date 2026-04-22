<?php

declare(strict_types=1);

namespace App\Domain\Repository;

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
}
