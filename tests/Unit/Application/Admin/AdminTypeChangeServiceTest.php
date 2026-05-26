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

namespace App\Tests\Unit\Application\Admin;

use App\Application\Admin\AdminTypeChangeService;
use App\Application\Approval\LeaveRequestEntitlementBookerInterface;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AdminTypeChangeService::class)]
#[AllowMockObjectsWithoutExpectations]
final class AdminTypeChangeServiceTest extends TestCase
{
    private LeaveRequestEntitlementBookerInterface&MockObject $booker;
    private NotificationDispatcherInterface&MockObject $dispatcher;
    private EntityManagerInterface&MockObject $entityManager;
    private MockClock $clock;
    private AdminTypeChangeService $service;

    private Company $company;
    private Employee $erikEmployee;
    private User $erikUser;
    private Employee $adminEmployee;
    private AbsenceType $urlaub;
    private AbsenceType $sonderurlaub;
    private AbsenceType $krankheit;

    protected function setUp(): void
    {
        $this->booker = $this->createMock(LeaveRequestEntitlementBookerInterface::class);
        $this->dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = new MockClock('2026-05-07 10:00:00');
        $this->service = new AdminTypeChangeService(
            $this->booker,
            $this->dispatcher,
            $this->entityManager,
            $this->clock,
        );

        $this->company = new Company('Acme GmbH');
        $location = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $schedule = WorkSchedule::autoDistribute(40.0, [
            Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday,
        ]);

        $this->erikUser = new User($this->company, 'erik@acme.test', UserRole::Employee);
        $this->erikEmployee = new Employee(
            $this->company,
            'Erik Employee',
            'EMP-0002',
            $location,
            $schedule,
            new \DateTimeImmutable('2025-03-01'),
            $this->erikUser,
        );

        $this->adminEmployee = new Employee(
            $this->company,
            'Anna Admin',
            'EMP-0001',
            $location,
            $schedule,
            new \DateTimeImmutable('2024-01-01'),
        );

        $this->urlaub = new AbsenceType($this->company, 'Urlaub', true, true, '#3B82F6');
        $this->sonderurlaub = new AbsenceType($this->company, 'Sonderurlaub', false, true, '#F59E0B');
        $this->krankheit = new AbsenceType($this->company, 'Krankheit', false, false, '#EF4444');
    }

    #[Test]
    public function changesTypeReleasesAndReconsumesEntitlement(): void
    {
        $request = $this->approvedRequest($this->urlaub);

        $persistedAuditEntries = [];
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedAuditEntries): void {
                if ($entity instanceof LeaveRequestAuditEntry) {
                    $persistedAuditEntries[] = $entity;
                }
            });

        // release uses OLD type, consume uses NEW type — both run.
        $this->booker->expects(self::once())->method('release')->with($request);
        $this->booker->expects(self::once())->method('consume')->with($request);

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                NotificationType::AdminTypeChange,
                $this->erikUser,
                self::callback(static fn (array $payload): bool => 'Urlaub' === $payload['oldTypeName']
                        && 'Sonderurlaub' === $payload['newTypeName']
                        && 'Falsch klassifiziert.' === $payload['reason']
                        && 'Anna Admin' === $payload['adminName']),
                'leave_request',
                null,  // request id is null in unit test (no DB)
            );

        $this->service->changeAbsenceType(
            $request,
            $this->sonderurlaub,
            'Falsch klassifiziert.',
            $this->adminEmployee,
        );

        self::assertSame($this->sonderurlaub, $request->getAbsenceType());
        self::assertCount(1, $persistedAuditEntries);
        self::assertSame($this->urlaub, $persistedAuditEntries[0]->getFromAbsenceType());
        self::assertSame($this->sonderurlaub, $persistedAuditEntries[0]->getToAbsenceType());
        self::assertSame('Falsch klassifiziert.', $persistedAuditEntries[0]->getReason());
        self::assertSame($this->adminEmployee, $persistedAuditEntries[0]->getActor());
    }

    #[Test]
    public function rejectsChangeWhenNewTypeEqualsCurrent(): void
    {
        $request = $this->approvedRequest($this->urlaub);

        $this->booker->expects(self::never())->method('release');
        $this->booker->expects(self::never())->method('consume');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('same type');

        $this->service->changeAbsenceType(
            $request,
            $this->urlaub,
            'No-op attempt.',
            $this->adminEmployee,
        );
    }

    #[Test]
    public function rejectsChangeWhenRequestNotApproved(): void
    {
        // Pending requests have no entitlement booking yet — type-change makes
        // no semantic sense (manager should reject + employee resubmit).
        $pendingRequest = $this->pendingRequest($this->urlaub);

        $this->booker->expects(self::never())->method('release');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('approved');

        $this->service->changeAbsenceType(
            $pendingRequest,
            $this->sonderurlaub,
            'Whatever.',
            $this->adminEmployee,
        );
    }

    #[Test]
    public function rejectsEmptyReason(): void
    {
        $request = $this->approvedRequest($this->urlaub);

        $this->booker->expects(self::never())->method('release');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reason');

        $this->service->changeAbsenceType($request, $this->sonderurlaub, '   ', $this->adminEmployee);
    }

    #[Test]
    public function bubblesOverdraftFromConsume(): void
    {
        // Krankheit (no-deduct) -> Urlaub (deducts) with insufficient balance:
        // release is a no-op for non-deducting old type, then consume throws.
        $request = $this->approvedRequest($this->krankheit);

        $this->booker->method('release');  // no-op for non-deducting type
        $this->booker->method('consume')
            ->willThrowException(new \DomainException('Insufficient entitlement balance.'));

        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Insufficient');

        $this->service->changeAbsenceType($request, $this->urlaub, 'Reclassify.', $this->adminEmployee);
    }

    #[Test]
    public function silentlySkipsNotificationWhenEmployeeHasNoUserAccount(): void
    {
        // Employee without User account — e.g. archived ex-employee whose
        // request the admin is correcting retroactively. Audit still happens.
        $orphanEmployee = new Employee(
            $this->company,
            'Hannah History',
            'EMP-9999',
            $this->erikEmployee->getLocation(),
            $this->erikEmployee->getWorkSchedule(),
            new \DateTimeImmutable('2019-05-01'),
            null,  // no user account
        );
        $request = $this->approvedRequestFor($orphanEmployee, $this->urlaub);

        $this->booker->method('release');
        $this->booker->method('consume');

        $this->dispatcher->expects(self::never())->method('dispatch');
        $this->entityManager->expects(self::atLeastOnce())->method('persist');

        $this->service->changeAbsenceType($request, $this->sonderurlaub, 'Reclassify.', $this->adminEmployee);

        self::assertSame($this->sonderurlaub, $request->getAbsenceType());
    }

    private function approvedRequest(AbsenceType $type): LeaveRequest
    {
        return $this->approvedRequestFor($this->erikEmployee, $type);
    }

    private function approvedRequestFor(Employee $employee, AbsenceType $type): LeaveRequest
    {
        $request = new LeaveRequest(
            $employee,
            $type,
            new \DateTimeImmutable('2026-07-06'),
            new \DateTimeImmutable('2026-07-10'),
            LeaveDayType::FullDay,
            new \DateTimeImmutable('2026-04-15 10:00:00'),
        );
        $request->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-07-06'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-07'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-08'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-09'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-10'), 8.0, LeaveDayStatus::Working),
        ]));
        $request->setStatus(LeaveRequestStatus::Approved);

        return $request;
    }

    private function pendingRequest(AbsenceType $type): LeaveRequest
    {
        $request = new LeaveRequest(
            $this->erikEmployee,
            $type,
            new \DateTimeImmutable('2026-07-06'),
            new \DateTimeImmutable('2026-07-06'),
            LeaveDayType::FullDay,
            new \DateTimeImmutable('2026-04-15 10:00:00'),
        );
        $request->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-07-06'), 8.0, LeaveDayStatus::Working),
        ]));
        // Constructor sets Pending for approval-required types — leave as is.

        return $request;
    }
}
