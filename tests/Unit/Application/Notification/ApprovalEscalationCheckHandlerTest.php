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

use App\Application\Notification\ApprovalEscalationCheckHandler;
use App\Application\Notification\ApprovalEscalationCheckMessage;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\Repository\UserRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Unit tests for ApprovalEscalationCheckHandler.
 *
 * Fired by the Symfony Scheduler (hourly). Loads Pending LeaveRequests that
 * have exceeded their company's `approvalEscalationDays` threshold AND have
 * not yet been escalated, then dispatches an EscalationTriggered notification
 * to every active admin User in that company.
 */
#[CoversClass(ApprovalEscalationCheckHandler::class)]
final class ApprovalEscalationCheckHandlerTest extends TestCase
{
    private NotificationDispatcherInterface&MockObject $dispatcher;
    private LeaveRequestRepository&MockObject $leaveRequestRepository;
    private UserRepository&MockObject $userRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MockClock $clock;
    private Company $company;
    private Location $hq;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $this->leaveRequestRepository = $this->createMock(LeaveRequestRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = new MockClock('2026-05-02 04:00:00', 'UTC');

        $this->company = new Company('Acme GmbH');
        $this->hq = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->urlaub = new AbsenceType($this->company, 'Urlaub', true, true, '#3B82F6');
    }

    private function createHandler(): ApprovalEscalationCheckHandler
    {
        // Always-enabled stub — these tests don't care about the toggle layer
        // (#35 phase 2), they exercise the escalation logic. The toggle's own
        // skip behavior is covered separately in YearTransitionHandlerTest +
        // ScheduledJobConfigManagerTest.
        $jobConfig = $this->createMock(\App\Application\Scheduler\ScheduledJobConfigManagerInterface::class);
        $jobConfig->method('isEnabled')->willReturn(true);

        return new ApprovalEscalationCheckHandler(
            $this->leaveRequestRepository,
            $this->userRepository,
            $this->dispatcher,
            $this->entityManager,
            $jobConfig,
            $this->clock,
        );
    }

    private function createPendingRequest(string $employeeName, string $employeeNumber): LeaveRequest
    {
        $employee = new Employee(
            company: $this->company,
            fullName: $employeeName,
            employeeNumber: $employeeNumber,
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );

        return new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-04-25 09:00:00'),
        );
    }

    private function createAdmin(string $email): User
    {
        return new User($this->company, $email, UserRole::Admin);
    }

    #[Test]
    public function notifiesEachAdminPerEscalatedRequest(): void
    {
        $request = $this->createPendingRequest('Jane Doe', 'EMP-0001');
        $adminA = $this->createAdmin('admin-a@acme.test');
        $adminB = $this->createAdmin('admin-b@acme.test');

        $this->leaveRequestRepository->expects(self::once())
            ->method('findPendingNeedingEscalation')
            ->willReturn([$request]);
        $this->userRepository->expects(self::once())
            ->method('findActiveAdminsByCompany')
            ->with($this->company)
            ->willReturn([$adminA, $adminB]);

        $captured = [];
        $this->dispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (NotificationType $type, User $recipient, array $payload) use (&$captured) {
                $captured[] = ['type' => $type, 'recipient' => $recipient, 'payload' => $payload];

                return $this->createStub(\App\Domain\Entity\Notification::class);
            });
        $this->entityManager->expects(self::once())->method('flush');

        ($this->createHandler())(new ApprovalEscalationCheckMessage());

        self::assertCount(2, $captured);
        foreach ($captured as $entry) {
            self::assertSame(NotificationType::EscalationTriggered, $entry['type']);
            self::assertSame('Jane Doe', $entry['payload']['employeeName']);
            self::assertSame('Urlaub', $entry['payload']['absenceTypeName']);
            self::assertSame('06.07.2026', $entry['payload']['startDate']);
            self::assertSame('10.07.2026', $entry['payload']['endDate']);
        }
        self::assertSame(['admin-a@acme.test', 'admin-b@acme.test'], [
            $captured[0]['recipient']->getEmail(),
            $captured[1]['recipient']->getEmail(),
        ]);
    }

    #[Test]
    public function payloadIncludesDaysWaiting(): void
    {
        // Request requestedAt 2026-04-25 09:00, now 2026-05-02 04:00 → 7 calendar days
        // (April has 30 days: 25→26→27→28→29→30→01→02).
        $request = $this->createPendingRequest('Jane Doe', 'EMP-0001');
        $admin = $this->createAdmin('admin@acme.test');

        $this->leaveRequestRepository->method('findPendingNeedingEscalation')
            ->willReturn([$request]);
        $this->userRepository->method('findActiveAdminsByCompany')->willReturn([$admin]);

        $capturedPayload = null;
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (NotificationType $type, User $recipient, array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return $this->createStub(\App\Domain\Entity\Notification::class);
            });

        ($this->createHandler())(new ApprovalEscalationCheckMessage());

        self::assertNotNull($capturedPayload);
        self::assertSame(7, $capturedPayload['daysWaiting']);
    }

    #[Test]
    public function marksRequestEscalatedToPreventReNotification(): void
    {
        $request = $this->createPendingRequest('Jane Doe', 'EMP-0001');
        $admin = $this->createAdmin('admin@acme.test');

        $this->leaveRequestRepository->method('findPendingNeedingEscalation')
            ->willReturn([$request]);
        $this->userRepository->method('findActiveAdminsByCompany')->willReturn([$admin]);
        $this->dispatcher->method('dispatch')
            ->willReturn($this->createStub(\App\Domain\Entity\Notification::class));

        ($this->createHandler())(new ApprovalEscalationCheckMessage());

        $stamp = $request->getEscalationNotifiedAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $stamp);
        self::assertSame('2026-05-02 04:00:00', $stamp->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function skipsRequestsWhenCompanyHasNoActiveAdmins(): void
    {
        $request = $this->createPendingRequest('Jane Doe', 'EMP-0001');

        $this->leaveRequestRepository->method('findPendingNeedingEscalation')
            ->willReturn([$request]);
        $this->userRepository->method('findActiveAdminsByCompany')->willReturn([]);

        $this->dispatcher->expects(self::never())->method('dispatch');

        ($this->createHandler())(new ApprovalEscalationCheckMessage());

        // Stamp must remain null so the next sweep retries once an admin
        // is hired (rare but possible in real SMB workflows).
        self::assertNull($request->getEscalationNotifiedAt());
    }

    #[Test]
    public function passesNowToRepositoryQuery(): void
    {
        $captured = null;
        $this->leaveRequestRepository->expects(self::once())
            ->method('findPendingNeedingEscalation')
            ->willReturnCallback(static function (\DateTimeImmutable $now) use (&$captured): array {
                $captured = $now;

                return [];
            });

        ($this->createHandler())(new ApprovalEscalationCheckMessage());

        self::assertNotNull($captured);
        self::assertSame('2026-05-02 04:00:00', $captured->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function handlesEmptyResultGracefully(): void
    {
        $this->leaveRequestRepository->expects(self::once())
            ->method('findPendingNeedingEscalation')
            ->willReturn([]);

        $this->userRepository->expects(self::never())->method('findActiveAdminsByCompany');
        $this->dispatcher->expects(self::never())->method('dispatch');
        $this->entityManager->expects(self::once())->method('flush');

        ($this->createHandler())(new ApprovalEscalationCheckMessage());
    }
}
