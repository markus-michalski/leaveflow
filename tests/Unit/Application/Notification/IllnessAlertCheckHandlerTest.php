<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\IllnessAlertCheckHandler;
use App\Application\Notification\IllnessAlertCheckMessage;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Application\Scheduler\ScheduledJobConfigManagerInterface;
use App\Domain\Calculator\IllnessRunCalculator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\IllnessAlert;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\IllnessAlertRepository;
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
 * Unit tests for IllnessAlertCheckHandler.
 *
 * Daily sweep: walks active employees per company, runs the
 * IllnessRunCalculator over their illness-tracking LeaveRequests, and
 * dispatches the IllnessSixWeekAlert notification when the §3 EntgFG
 * 42-day threshold is reached. Idempotency lives in the
 * `illness_alerts` table — we verify both the dispatch path and the
 * skip-when-already-alerted path.
 */
#[CoversClass(IllnessAlertCheckHandler::class)]
final class IllnessAlertCheckHandlerTest extends TestCase
{
    private NotificationDispatcherInterface&MockObject $dispatcher;
    private EmployeeRepository&MockObject $employeeRepository;
    private LeaveRequestRepository&MockObject $leaveRequestRepository;
    private IllnessAlertRepository&MockObject $illnessAlertRepository;
    private UserRepository&MockObject $userRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private ScheduledJobConfigManagerInterface&MockObject $jobConfig;
    private MockClock $clock;
    private Company $company;
    private Location $hq;
    private AbsenceType $sick;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $this->employeeRepository = $this->createMock(EmployeeRepository::class);
        $this->leaveRequestRepository = $this->createMock(LeaveRequestRepository::class);
        $this->illnessAlertRepository = $this->createMock(IllnessAlertRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->jobConfig = $this->createMock(ScheduledJobConfigManagerInterface::class);
        $this->jobConfig->method('isEnabled')->willReturn(true);
        $this->clock = new MockClock('2026-05-12 06:00:00', 'UTC');

        $this->company = new Company('Acme GmbH');
        $this->hq = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->sick = new AbsenceType($this->company, 'Krankheit', false, false, '#EF4444', illnessTracking: true);
    }

    #[Test]
    public function dispatchesAlertToDepartmentLeadWhenThresholdReached(): void
    {
        $leadEmployee = $this->createEmployee('Lena Lead', 'EMP-LEAD');
        $leadUser = new User($this->company, 'lena@acme.test', UserRole::Manager);
        $this->setUser($leadEmployee, $leadUser);

        $reportee = $this->createEmployee('Erik Reportee', 'EMP-1');
        $department = new Department($this->company, 'Engineering', $leadEmployee, null);
        $reportee->assignToDepartment($department);

        $illnessReq = $this->illnessRequest($reportee, '2026-04-01', '2026-05-12'); // 42 days

        $this->employeeRepository->method('findAllActive')->willReturn([$reportee]);
        $this->leaveRequestRepository->method('findIllnessRequestsForEmployee')
            ->with($reportee)
            ->willReturn([$illnessReq]);
        $this->illnessAlertRepository->method('existsForEmployeePeriod')->willReturn(false);

        /** @var list<array{type: NotificationType, recipient: User, payload: array<string, mixed>, relatedEntityType: ?string}> $captured */
        $captured = [];
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (NotificationType $type, User $recipient, array $payload, ?string $relatedEntityType, ?int $relatedEntityId) use (&$captured): Notification {
                $captured[] = [
                    'type' => $type,
                    'recipient' => $recipient,
                    'payload' => $payload,
                    'relatedEntityType' => $relatedEntityType,
                ];

                return $this->createStub(Notification::class);
            });

        /** @var list<IllnessAlert> $persistedAlerts */
        $persistedAlerts = [];
        $this->entityManager->expects(self::atLeastOnce())
            ->method('persist')
            ->willReturnCallback(static function (object $obj) use (&$persistedAlerts): void {
                if ($obj instanceof IllnessAlert) {
                    $persistedAlerts[] = $obj;
                }
            });
        $this->entityManager->expects(self::once())->method('flush');

        $this->jobConfig->expects(self::once())
            ->method('markRun')
            ->with(IllnessAlertCheckHandler::JOB_NAME, ScheduledJobRunStatus::Success);

        ($this->createHandler())(new IllnessAlertCheckMessage());

        self::assertCount(1, $captured);
        $entry = $captured[0];
        self::assertSame(NotificationType::IllnessSixWeekAlert, $entry['type']);
        self::assertSame($leadUser, $entry['recipient']);
        self::assertSame('Erik Reportee', $entry['payload']['employeeName']);
        self::assertSame('EMP-1', $entry['payload']['employeeNumber']);
        self::assertSame(42, $entry['payload']['daysCount']);
        self::assertSame('01.04.2026', $entry['payload']['periodStartedOn']);
        self::assertSame('12.05.2026', $entry['payload']['periodEndsOn']);
        self::assertSame(42, $entry['payload']['thresholdDays']);
        self::assertSame(Employee::class, $entry['relatedEntityType']);

        self::assertCount(1, $persistedAlerts);
        self::assertSame(42, $persistedAlerts[0]->getDaysCount());
        self::assertSame('2026-04-01', $persistedAlerts[0]->getPeriodStartedOn()->format('Y-m-d'));
    }

    #[Test]
    public function fallsBackToCompanyAdminsWhenNoDepartmentLead(): void
    {
        $reportee = $this->createEmployee('Erik Reportee', 'EMP-1');
        // No department at all → straight to admin fallback.

        $illnessReq = $this->illnessRequest($reportee, '2026-04-01', '2026-05-12');

        $admin = new User($this->company, 'admin@acme.test', UserRole::Admin);

        $this->employeeRepository->method('findAllActive')->willReturn([$reportee]);
        $this->leaveRequestRepository->method('findIllnessRequestsForEmployee')->willReturn([$illnessReq]);
        $this->illnessAlertRepository->method('existsForEmployeePeriod')->willReturn(false);
        $this->userRepository->expects(self::once())
            ->method('findActiveAdminsByCompany')
            ->with($this->company)
            ->willReturn([$admin]);

        /** @var list<User> $recipients */
        $recipients = [];
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (NotificationType $type, User $recipient, array $payload) use (&$recipients): Notification {
                $recipients[] = $recipient;

                return $this->createStub(Notification::class);
            });

        ($this->createHandler())(new IllnessAlertCheckMessage());

        self::assertSame([$admin], $recipients);
    }

    #[Test]
    public function skipsWhenAlertAlreadyExistsForSamePeriod(): void
    {
        $reportee = $this->createEmployee('Erik Reportee', 'EMP-1');
        $illnessReq = $this->illnessRequest($reportee, '2026-04-01', '2026-05-12');

        $this->employeeRepository->method('findAllActive')->willReturn([$reportee]);
        $this->leaveRequestRepository->method('findIllnessRequestsForEmployee')->willReturn([$illnessReq]);
        $this->illnessAlertRepository->expects(self::once())
            ->method('existsForEmployeePeriod')
            ->with($reportee, self::callback(static fn (\DateTimeImmutable $d): bool => '2026-04-01' === $d->format('Y-m-d')))
            ->willReturn(true);

        $this->dispatcher->expects(self::never())->method('dispatch');
        $this->entityManager->expects(self::never())->method('persist');

        ($this->createHandler())(new IllnessAlertCheckMessage());
    }

    #[Test]
    public function skipsWhenIllnessRunBelowThreshold(): void
    {
        $reportee = $this->createEmployee('Erik Reportee', 'EMP-1');
        $shortReq = $this->illnessRequest($reportee, '2026-05-01', '2026-05-10'); // 10 days

        $this->employeeRepository->method('findAllActive')->willReturn([$reportee]);
        $this->leaveRequestRepository->method('findIllnessRequestsForEmployee')->willReturn([$shortReq]);

        $this->illnessAlertRepository->expects(self::never())->method('existsForEmployeePeriod');
        $this->dispatcher->expects(self::never())->method('dispatch');

        ($this->createHandler())(new IllnessAlertCheckMessage());
    }

    #[Test]
    public function skipsEmployeeWithoutAnyIllnessRequests(): void
    {
        $reportee = $this->createEmployee('Erik Reportee', 'EMP-1');

        $this->employeeRepository->method('findAllActive')->willReturn([$reportee]);
        $this->leaveRequestRepository->method('findIllnessRequestsForEmployee')->willReturn([]);

        $this->dispatcher->expects(self::never())->method('dispatch');

        ($this->createHandler())(new IllnessAlertCheckMessage());
    }

    #[Test]
    public function leavesAlertUnrecordedWhenNoRecipientResolvable(): void
    {
        $reportee = $this->createEmployee('Erik Reportee', 'EMP-1');
        $illnessReq = $this->illnessRequest($reportee, '2026-04-01', '2026-05-12');

        $this->employeeRepository->method('findAllActive')->willReturn([$reportee]);
        $this->leaveRequestRepository->method('findIllnessRequestsForEmployee')->willReturn([$illnessReq]);
        $this->illnessAlertRepository->method('existsForEmployeePeriod')->willReturn(false);
        $this->userRepository->method('findActiveAdminsByCompany')->willReturn([]);

        $this->dispatcher->expects(self::never())->method('dispatch');
        $this->entityManager->expects(self::never())->method('persist');

        ($this->createHandler())(new IllnessAlertCheckMessage());
    }

    #[Test]
    public function respectsJobConfigToggle(): void
    {
        $jobConfig = $this->createMock(ScheduledJobConfigManagerInterface::class);
        $jobConfig->method('isEnabled')->willReturn(false);
        $jobConfig->expects(self::once())
            ->method('markRun')
            ->with(IllnessAlertCheckHandler::JOB_NAME, ScheduledJobRunStatus::Skipped);

        $this->employeeRepository->expects(self::never())->method('findAllActive');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $handler = new IllnessAlertCheckHandler(
            $this->employeeRepository,
            $this->leaveRequestRepository,
            $this->illnessAlertRepository,
            $this->userRepository,
            new IllnessRunCalculator(),
            $this->dispatcher,
            $this->entityManager,
            $jobConfig,
            $this->clock,
        );
        $handler(new IllnessAlertCheckMessage());
    }

    private function createHandler(): IllnessAlertCheckHandler
    {
        return new IllnessAlertCheckHandler(
            $this->employeeRepository,
            $this->leaveRequestRepository,
            $this->illnessAlertRepository,
            $this->userRepository,
            new IllnessRunCalculator(),
            $this->dispatcher,
            $this->entityManager,
            $this->jobConfig,
            $this->clock,
        );
    }

    private function createEmployee(string $name, string $number): Employee
    {
        return new Employee(
            company: $this->company,
            fullName: $name,
            employeeNumber: $number,
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
    }

    private function setUser(Employee $employee, User $user): void
    {
        $employee->linkUser($user);
    }

    private function illnessRequest(Employee $employee, string $start, string $end): LeaveRequest
    {
        return new LeaveRequest(
            employee: $employee,
            absenceType: $this->sick,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable($start),
        );
    }
}
