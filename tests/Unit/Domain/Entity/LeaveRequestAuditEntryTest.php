<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LeaveRequestAuditEntry — a single row in the append-only
 * audit log for a LeaveRequest's state transitions.
 */
#[CoversClass(LeaveRequestAuditEntry::class)]
final class LeaveRequestAuditEntryTest extends TestCase
{
    private LeaveRequest $request;
    private Employee $actor;

    protected function setUp(): void
    {
        $acme = new Company('Acme GmbH');
        $hq = new Location($acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $employee = new Employee(
            company: $acme,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->actor = new Employee(
            company: $acme,
            fullName: 'Max Mustermann',
            employeeNumber: 'EMP-0002',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
        );
        $urlaub = new AbsenceType(
            company: $acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->request = new LeaveRequest(
            employee: $employee,
            absenceType: $urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:30:00'),
        );
    }

    #[Test]
    public function storesCoreFields(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-05-02 10:15:00');

        $entry = new LeaveRequestAuditEntry(
            leaveRequest: $this->request,
            actor: $this->actor,
            transition: 'approve',
            fromStatus: LeaveRequestStatus::Pending,
            toStatus: LeaveRequestStatus::Approved,
            occurredAt: $occurredAt,
        );

        self::assertSame($this->request, $entry->getLeaveRequest());
        self::assertSame($this->actor, $entry->getActor());
        self::assertSame('approve', $entry->getTransition());
        self::assertSame(LeaveRequestStatus::Pending, $entry->getFromStatus());
        self::assertSame(LeaveRequestStatus::Approved, $entry->getToStatus());
        self::assertSame($occurredAt, $entry->getOccurredAt());
        self::assertNull($entry->getReason());
    }

    #[Test]
    public function storesOptionalReason(): void
    {
        $entry = new LeaveRequestAuditEntry(
            leaveRequest: $this->request,
            actor: $this->actor,
            transition: 'reject',
            fromStatus: LeaveRequestStatus::Pending,
            toStatus: LeaveRequestStatus::Rejected,
            occurredAt: new \DateTimeImmutable('2026-05-02 10:15:00'),
            reason: 'Teambesetzung im Zeitraum nicht ausreichend',
        );

        self::assertSame('Teambesetzung im Zeitraum nicht ausreichend', $entry->getReason());
    }

    #[Test]
    public function allowsNullActorForSystemTriggeredEntries(): void
    {
        // Reserved for future automated transitions (e.g. scheduled
        // auto-cancellation when an employee leaves the company). Phase 6 only
        // creates entries with a human actor, but the column is nullable so
        // later phases don't need a schema change.
        $entry = new LeaveRequestAuditEntry(
            leaveRequest: $this->request,
            actor: null,
            transition: 'cancel_pending',
            fromStatus: LeaveRequestStatus::Pending,
            toStatus: LeaveRequestStatus::Cancelled,
            occurredAt: new \DateTimeImmutable('2026-05-02 10:15:00'),
        );

        self::assertNull($entry->getActor());
    }

    #[Test]
    public function rejectsEmptyTransitionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transition');

        new LeaveRequestAuditEntry(
            leaveRequest: $this->request,
            actor: $this->actor,
            transition: '',
            fromStatus: LeaveRequestStatus::Pending,
            toStatus: LeaveRequestStatus::Approved,
            occurredAt: new \DateTimeImmutable('2026-05-02 10:15:00'),
        );
    }

    #[Test]
    public function rejectsUnchangedStatus(): void
    {
        // A state transition that doesn't change state is nonsensical — catch
        // misconfigured workflows or bugs in the subscriber early.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must differ');

        new LeaveRequestAuditEntry(
            leaveRequest: $this->request,
            actor: $this->actor,
            transition: 'approve',
            fromStatus: LeaveRequestStatus::Pending,
            toStatus: LeaveRequestStatus::Pending,
            occurredAt: new \DateTimeImmutable('2026-05-02 10:15:00'),
        );
    }
}
