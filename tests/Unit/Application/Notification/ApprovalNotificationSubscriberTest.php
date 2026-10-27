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

namespace App\Tests\Unit\Application\Notification;

use App\Application\Approval\ApproverResolverInterface;
use App\Application\Notification\ApprovalNotificationSubscriber;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

/**
 * Unit tests for ApprovalNotificationSubscriber.
 *
 * Subscribes to workflow.leave_request_approval.completed and dispatches the
 * matching NotificationType based on the transition. Five of the seven
 * transitions are notification sources:
 *   approve, reject               -> ApprovalDecided to employee
 *   request_cancel                -> CancelRequested to approver
 *   confirm_cancel, deny_cancel   -> CancelDecided to employee
 *
 * The remaining two (cancel_pending, cancel_recorded) are user-initiated
 * self-cancellations of unapproved requests and produce no notification.
 */
#[CoversClass(ApprovalNotificationSubscriber::class)]
final class ApprovalNotificationSubscriberTest extends TestCase
{
    private NotificationDispatcherInterface&MockObject $dispatcher;
    private ApproverResolverInterface&MockObject $approverResolver;
    private LeaveRequest $request;
    private Employee $employee;
    private User $employeeUser;
    private Employee $approver;
    private User $approverUser;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $this->approverResolver = $this->createMock(ApproverResolverInterface::class);

        $acme = new Company('Acme GmbH');
        $hq = new Location($acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $department = new Department($acme, 'Engineering');

        $this->employeeUser = new User($acme, 'jane@acme.test', UserRole::Employee);
        $this->employee = new Employee(
            company: $acme,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: $this->employeeUser,
        );
        $this->employee->assignToDepartment($department);

        $this->approverUser = new User($acme, 'maya@acme.test', UserRole::Manager);
        $this->approver = new Employee(
            company: $acme,
            fullName: 'Maya Manager',
            employeeNumber: 'EMP-0002',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
            user: $this->approverUser,
        );
        $this->approver->assignToDepartment($department);

        $urlaub = new AbsenceType(
            company: $acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );

        $this->request = new LeaveRequest(
            employee: $this->employee,
            absenceType: $urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:30:00'),
        );
    }

    private function createSubscriber(): ApprovalNotificationSubscriber
    {
        return new ApprovalNotificationSubscriber(
            $this->dispatcher,
            $this->approverResolver,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return CompletedEvent<LeaveRequest>
     */
    private function buildEvent(string $transitionName, string $from, string $to, array $context = []): CompletedEvent
    {
        return new CompletedEvent(
            subject: $this->request,
            marking: new Marking([$to => 1]),
            transition: new Transition($transitionName, $from, $to),
            workflow: null,
            context: $context,
        );
    }

    #[Test]
    public function notifiesEmployeeOnApprove(): void
    {
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                type: NotificationType::ApprovalDecided,
                recipient: $this->employeeUser,
                payload: self::callback(static fn (array $p): bool => 'approved' === $p['decision']
                        && 'Maya Manager' === $p['approverName']
                        && 'Urlaub' === $p['absenceTypeName']
                        && '06.07.2026' === $p['startDate']
                        && '10.07.2026' === $p['endDate']),
                relatedEntityType: LeaveRequest::class,
                relatedEntityId: self::anything(),
            );

        $event = $this->buildEvent('approve', 'pending', 'approved', ['actor' => $this->approver]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function notifiesEmployeeOnRejectWithReason(): void
    {
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                type: NotificationType::ApprovalDecided,
                recipient: $this->employeeUser,
                payload: self::callback(static fn (array $p): bool => 'rejected' === $p['decision']
                        && 'Teambesetzung im Zeitraum' === $p['reason']),
                relatedEntityType: LeaveRequest::class,
                relatedEntityId: self::anything(),
            );

        $event = $this->buildEvent('reject', 'pending', 'rejected', [
            'actor' => $this->approver,
            'reason' => 'Teambesetzung im Zeitraum',
        ]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function notifiesApproverOnRequestCancel(): void
    {
        // Employee asks to cancel their approved leave -> approver decides.
        // The actor in context IS the employee, but recipient must resolve to
        // the manager via ApproverResolver.
        $this->approverResolver->expects(self::once())
            ->method('resolve')
            ->with($this->request)
            ->willReturn($this->approver);

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                type: NotificationType::CancelRequested,
                recipient: $this->approverUser,
                payload: self::callback(static fn (array $p): bool => 'Jane Doe' === $p['employeeName']
                        && 'Urlaub' === $p['absenceTypeName']),
                relatedEntityType: LeaveRequest::class,
                relatedEntityId: self::anything(),
            );

        $event = $this->buildEvent('request_cancel', 'approved', 'cancel_requested', ['actor' => $this->employee]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function notifiesEmployeeOnConfirmCancel(): void
    {
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                type: NotificationType::CancelDecided,
                recipient: $this->employeeUser,
                payload: self::callback(static fn (array $p): bool => 'confirmed' === $p['decision']
                        && 'Maya Manager' === $p['approverName']),
                relatedEntityType: LeaveRequest::class,
                relatedEntityId: self::anything(),
            );

        $event = $this->buildEvent('confirm_cancel', 'cancel_requested', 'cancelled', ['actor' => $this->approver]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function notifiesEmployeeOnDenyCancel(): void
    {
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                type: NotificationType::CancelDecided,
                recipient: $this->employeeUser,
                payload: self::callback(static fn (array $p): bool => 'denied' === $p['decision']
                        && 'noch in Planung beim Kunden' === $p['reason']),
                relatedEntityType: LeaveRequest::class,
                relatedEntityId: self::anything(),
            );

        $event = $this->buildEvent('deny_cancel', 'cancel_requested', 'approved', [
            'actor' => $this->approver,
            'reason' => 'noch in Planung beim Kunden',
        ]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function notifiesApproverOnCancelPending(): void
    {
        // Employee withdraws a Pending request before the manager decided.
        // The manager already received ApprovalRequested + email; without
        // this RequestWithdrawn signal, the original notification stays
        // stale and the manager clicks through to a cancelled request
        // with no explanation.
        $this->approverResolver->expects(self::once())
            ->method('resolve')
            ->with($this->request)
            ->willReturn($this->approver);

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                type: NotificationType::RequestWithdrawn,
                recipient: $this->approverUser,
                payload: self::callback(static fn (array $p): bool => 'Jane Doe' === $p['employeeName']
                        && 'Urlaub' === $p['absenceTypeName']),
                relatedEntityType: LeaveRequest::class,
                relatedEntityId: self::anything(),
            );

        $event = $this->buildEvent('cancel_pending', 'pending', 'cancelled', ['actor' => $this->employee]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function ignoresCancelRecordedTransition(): void
    {
        // Recorded requests (Krankheit etc.) never fired ApprovalRequested,
        // so withdrawing one carries no notification debt.
        $this->dispatcher->expects(self::never())->method('dispatch');

        $event = $this->buildEvent('cancel_recorded', 'recorded', 'cancelled', ['actor' => $this->employee]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function ignoresEventForNonLeaveRequestSubject(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatch');

        $event = new CompletedEvent(
            subject: new \stdClass(),
            marking: new Marking(['approved' => 1]),
            transition: new Transition('approve', 'pending', 'approved'),
        );
        // @phpstan-ignore argument.type (intentional stdClass subject to test guard branch)
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function ignoresEventWithoutTransition(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatch');

        $event = new CompletedEvent(
            subject: $this->request,
            marking: new Marking(['approved' => 1]),
            transition: null,
        );
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function skipsWhenEmployeeHasNoUserAccount(): void
    {
        // Phase 2 allows Employee without a User account (DSGVO retention case
        // for ex-employees, or imports). No User -> no in-app inbox to send to.
        $this->employee->unlinkUser();

        $this->dispatcher->expects(self::never())->method('dispatch');

        $event = $this->buildEvent('approve', 'pending', 'approved', ['actor' => $this->approver]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function skipsWhenApproverHasNoUserAccount(): void
    {
        // Approver resolved to an Employee whose User was removed.
        $this->approver->unlinkUser();
        $this->approverResolver->expects(self::once())
            ->method('resolve')
            ->willReturn($this->approver);

        $this->dispatcher->expects(self::never())->method('dispatch');

        $event = $this->buildEvent('request_cancel', 'approved', 'cancel_requested', ['actor' => $this->employee]);
        ($this->createSubscriber())($event);
    }

    #[Test]
    public function skipsWhenApproverResolverReturnsNull(): void
    {
        // No active department lead/deputy -> ApproverResolver returns null.
        // Notification is silently skipped (Admin fallback for cancel-requests
        // is out of Phase 8 scope; lands with the escalation scheduler in
        // Slice 6).
        $this->approverResolver->expects(self::once())
            ->method('resolve')
            ->willReturn(null);

        $this->dispatcher->expects(self::never())->method('dispatch');

        $event = $this->buildEvent('request_cancel', 'approved', 'cancel_requested', ['actor' => $this->employee]);
        ($this->createSubscriber())($event);
    }
}
