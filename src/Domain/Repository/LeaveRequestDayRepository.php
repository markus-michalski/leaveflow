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
     * @return array<int, float> keyed by year, e.g. [2025 => 40.0, 2026 => 8.0]
     */
    public function sumPendingHoursByYear(Employee $employee): array
    {
        /** @var list<array{year: int, hours: float}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('YEAR(d.date) AS year', 'SUM(d.hours) AS hours')
            ->innerJoin('d.leaveRequest', 'r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.status = :status')
            ->andWhere('d.status != :excluded')
            ->setParameter('employee', $employee)
            ->setParameter('status', LeaveRequestStatus::Pending->value)
            ->setParameter('excluded', LeaveDayStatus::Excluded->value)
            ->groupBy('year')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['year']] = (float) $row['hours'];
        }

        return $result;
    }
}
