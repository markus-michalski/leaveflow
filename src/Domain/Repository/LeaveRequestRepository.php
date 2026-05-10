<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequest>
 */
class LeaveRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequest::class);
    }

    /**
     * Pending requests that have exceeded their company's escalation
     * threshold and have not been escalated yet. Drives the
     * ApprovalEscalationCheck scheduler.
     *
     * The threshold is per-company (Company.approvalEscalationDays), so we
     * compare requestedAt against `now - company.approvalEscalationDays
     * days` directly via DQL/SQL date arithmetic.
     *
     * @return list<LeaveRequest>
     */
    public function findPendingNeedingEscalation(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->innerJoin('e.company', 'c')
            ->where('r.status = :status')
            ->andWhere('r.escalationNotifiedAt IS NULL')
            ->andWhere("DATE_ADD(r.requestedAt, c.approvalEscalationDays, 'day') <= :now")
            ->setParameter('status', LeaveRequestStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Requests awaiting a manager decision, scoped to the approver's
     * department: Pending and CancelRequested. Four-eyes is enforced by
     * excluding the approver's own requests.
     *
     * @return list<LeaveRequest>
     */
    public function findActionableByApprover(Employee $approver): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->innerJoin('e.department', 'd')
            ->where('r.status IN (:statuses)')
            ->andWhere('d.active = true')
            ->andWhere('d.lead = :approver OR d.deputy = :approver')
            ->andWhere('e != :approver')
            ->setParameter('statuses', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::CancelRequested->value,
            ])
            ->setParameter('approver', $approver)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Manager history: every request routed to this approver regardless of
     * status — drives the "Alle" tab on /manager/approvals (#17). Mirrors
     * the access scope of {@see findActionableByApprover} (department lead
     * or deputy, excluding self) so a manager toggling between Open and All
     * never sees requests they couldn't act on in the first place.
     *
     * @return list<LeaveRequest>
     */
    public function findAllByApprover(Employee $approver): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->innerJoin('e.department', 'd')
            ->where('d.lead = :approver OR d.deputy = :approver')
            ->andWhere('e != :approver')
            ->setParameter('approver', $approver)
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Illness-tracking requests for a single employee, oldest first.
     * Drives the IllnessRunCalculator — only requests whose AbsenceType
     * has the isIllnessTracking flag set are returned.
     *
     * Statuses: Recorded (Krankheit's default since eAU) and Approved
     * (in case a company configures Krankheit to require approval).
     * Withdrawn / Rejected / Pending are excluded — those don't count
     * toward sick leave on the books.
     *
     * @return list<LeaveRequest>
     */
    public function findIllnessRequestsForEmployee(Employee $employee): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.absenceType', 't')
            ->where('r.employee = :employee')
            ->andWhere('t.illnessTracking = true')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('employee', $employee)
            ->setParameter('statuses', [
                LeaveRequestStatus::Recorded->value,
                LeaveRequestStatus::Approved->value,
            ])
            ->orderBy('r.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Per-employee history: every request from a single employee, optionally
     * scoped to a single year. Drives the per-employee drilldown (#18).
     * Sorted requestedAt-desc within each year so the view shows the most
     * recent action first.
     *
     * @return list<LeaveRequest>
     */
    public function findAllByEmployee(Employee $employee, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.employee = :employee')
            ->setParameter('employee', $employee)
            ->orderBy('r.startDate', 'DESC')
            ->addOrderBy('r.requestedAt', 'DESC');

        if (null !== $year) {
            // DQL doesn't ship a portable YEAR() function — use a half-open
            // range on startDate instead. Cross-year requests are bucketed by
            // their start; matches the user expectation when they pick "2026".
            $start = (new \DateTimeImmutable())->setDate($year, 1, 1)->setTime(0, 0);
            $end = (new \DateTimeImmutable())->setDate($year + 1, 1, 1)->setTime(0, 0);
            $qb->andWhere('r.startDate >= :year_start')
                ->andWhere('r.startDate < :year_end')
                ->setParameter('year_start', $start)
                ->setParameter('year_end', $end);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Admin view: every actionable request in the company regardless of
     * department membership. Separate query so manager and admin code paths
     * stay cleanly split.
     *
     * @return list<LeaveRequest>
     */
    public function findActionableInCompany(Company $company): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->where('r.status IN (:statuses)')
            ->andWhere('e.company = :company')
            ->setParameter('statuses', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::CancelRequested->value,
            ])
            ->setParameter('company', $company)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Admin "all" view — every request in the company, any status, newest
     * first. Counterpart to {@see findActionableInCompany} for the
     * /manager/approvals "Alle" toggle when an admin opens it.
     *
     * @return list<LeaveRequest>
     */
    public function findAllInCompany(Company $company): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->where('e.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pending requests in a company that have been sitting longer than
     * the given day threshold, oldest first. Drives the "liegende
     * Anträge"-action on the admin statistics dashboard. Independent of
     * {@see findPendingNeedingEscalation} — that one respects the
     * per-company escalation_days setting and idempotency stamp; this one
     * is a flat overview using the same threshold the dashboard explains
     * in its detail line.
     *
     * @return list<LeaveRequest>
     */
    public function findOverduePendingInCompany(
        Company $company,
        \DateTimeImmutable $now,
        int $daysThreshold,
    ): array {
        $cutoff = $now->modify(\sprintf('-%d days', $daysThreshold));

        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->where('r.status = :pending')
            ->andWhere('e.company = :company')
            ->andWhere('r.requestedAt <= :cutoff')
            ->setParameter('pending', LeaveRequestStatus::Pending->value)
            ->setParameter('company', $company)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count of pending and cancel-requested company-wide. Drives the
     * "open requests" KPI on the admin statistics dashboard.
     */
    public function countAwaitingDecisionInCompany(Company $company): int
    {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.employee', 'e')
            ->where('r.status IN (:statuses)')
            ->andWhere('e.company = :company')
            ->setParameter('statuses', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::CancelRequested->value,
            ])
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Approved leave requests overlapping the given range. Optional filters
     * narrow the result for the team-calendar view and capacity checks.
     *
     * Two ranges overlap when start_a <= end_b AND end_a >= start_b. The
     * employee/department joins are always present so callers can rely on
     * a consistent shape.
     *
     * @return list<LeaveRequest>
     */
    public function findApprovedOverlapping(
        Company $company,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        ?Department $department = null,
        ?AbsenceType $absenceType = null,
        ?Employee $excludingEmployee = null,
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->where('r.status = :approved')
            ->andWhere('e.company = :company')
            ->andWhere('r.startDate <= :rangeEnd')
            ->andWhere('r.endDate >= :rangeStart')
            ->setParameter('approved', LeaveRequestStatus::Approved->value)
            ->setParameter('company', $company)
            ->setParameter('rangeStart', $rangeStart->setTime(0, 0))
            ->setParameter('rangeEnd', $rangeEnd->setTime(0, 0))
            ->orderBy('r.startDate', 'ASC');

        if (null !== $department) {
            $qb->andWhere('e.department = :department')
                ->setParameter('department', $department);
        }

        if (null !== $absenceType) {
            $qb->andWhere('r.absenceType = :absenceType')
                ->setParameter('absenceType', $absenceType);
        }

        if (null !== $excludingEmployee) {
            $qb->andWhere('e != :excluding')
                ->setParameter('excluding', $excludingEmployee);
        }

        /** @var list<LeaveRequest> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
