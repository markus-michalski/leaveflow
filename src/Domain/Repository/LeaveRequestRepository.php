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
     * Personal iCal feed source: approved and recorded leave for the
     * given employee that overlaps the date range. Recorded illness is
     * included because it shows up on the employee's own profile too;
     * the team feed uses {@see findActiveOverlapping} and excludes
     * recorded entries for privacy.
     *
     * @return list<LeaveRequest>
     */
    public function findAbsencesForEmployeeInRange(
        Employee $employee,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        return $this->createQueryBuilder('r')
            ->where('r.employee = :employee')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.startDate <= :rangeEnd')
            ->andWhere('r.endDate >= :rangeStart')
            ->setParameter('employee', $employee)
            ->setParameter('statuses', [
                LeaveRequestStatus::Approved->value,
                LeaveRequestStatus::Recorded->value,
            ])
            ->setParameter('rangeStart', $rangeStart->setTime(0, 0))
            ->setParameter('rangeEnd', $rangeEnd->setTime(0, 0))
            ->orderBy('r.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Approved leave requests that overlap a given date — i.e. the
     * employees currently away on that day. Drives the "Aktuell abwesend"
     * card on the admin statistics dashboard. Sorted by employee name
     * for stable, alphabetical rendering, capped by `limit`.
     *
     * Excludes Recorded illness on purpose: the dashboard column lives
     * next to the team-calendar link, which by default doesn't show
     * recorded-only absences either. Counterpart {@see countActiveAbsencesOn}
     * returns the unfiltered total so the UI can show "+N more".
     *
     * @return list<LeaveRequest>
     */
    public function findActiveAbsencesOn(
        Company $company,
        \DateTimeImmutable $date,
        int $limit,
    ): array {
        $date = $date->setTime(0, 0);

        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->where('r.status IN (:statuses)')
            ->andWhere('e.company = :company')
            ->andWhere('r.startDate <= :date')
            ->andWhere('r.endDate >= :date')
            ->setParameter('statuses', [
                LeaveRequestStatus::Approved->value,
                LeaveRequestStatus::Recorded->value,
            ])
            ->setParameter('company', $company)
            ->setParameter('date', $date)
            ->orderBy('e.fullName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Total count for the "+N more"-link on the absences card. Mirrors
     * {@see findActiveAbsencesOn}'s WHERE clause exactly so the limit
     * vs. total math is consistent.
     */
    public function countActiveAbsencesOn(Company $company, \DateTimeImmutable $date): int
    {
        $date = $date->setTime(0, 0);

        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.employee', 'e')
            ->where('r.status IN (:statuses)')
            ->andWhere('e.company = :company')
            ->andWhere('r.startDate <= :date')
            ->andWhere('r.endDate >= :date')
            ->setParameter('statuses', [
                LeaveRequestStatus::Approved->value,
                LeaveRequestStatus::Recorded->value,
            ])
            ->setParameter('company', $company)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
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
     * Active (Approved + Recorded) leave requests overlapping the given range.
     * Optional filters narrow the result for the team-calendar view and capacity checks.
     *
     * Recorded illness is included because sick employees are absent and must
     * appear in the calendar, iCal feed, and capacity calculations just like
     * approved vacation — excluding them creates a visible inconsistency with
     * the "Aktuell abwesend" dashboard widget that uses both statuses.
     *
     * Two ranges overlap when start_a <= end_b AND end_a >= start_b. The
     * employee/department joins are always present so callers can rely on
     * a consistent shape.
     *
     * @return list<LeaveRequest>
     */
    public function findActiveOverlapping(
        Company $company,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        ?Department $department = null,
        ?AbsenceType $absenceType = null,
        ?Employee $excludingEmployee = null,
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->where('r.status IN (:statuses)')
            ->andWhere('e.company = :company')
            ->andWhere('r.startDate <= :rangeEnd')
            ->andWhere('r.endDate >= :rangeStart')
            ->setParameter('statuses', [LeaveRequestStatus::Approved->value, LeaveRequestStatus::Recorded->value])
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

    /**
     * Active and upcoming requests for a single employee — used on the personal
     * dashboard. "Active" means the request's end date is today or later, so
     * it covers requests that started in the past but haven't ended yet, as
     * well as future requests. Cancelled/Rejected are excluded; CancelRequested
     * is included because the employee should see it is still being processed.
     *
     * @return list<LeaveRequest>
     */
    public function findActiveAndUpcomingByEmployee(
        Employee $employee,
        \DateTimeImmutable $asOf,
        int $limit = 5,
    ): array {
        /** @var list<LeaveRequest> $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.employee = :employee')
            ->andWhere('r.endDate >= :today')
            ->andWhere('r.status NOT IN (:excluded)')
            ->setParameter('employee', $employee)
            ->setParameter('today', $asOf->setTime(0, 0))
            ->setParameter('excluded', [
                LeaveRequestStatus::Cancelled->value,
                LeaveRequestStatus::Rejected->value,
            ])
            ->orderBy('r.startDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
