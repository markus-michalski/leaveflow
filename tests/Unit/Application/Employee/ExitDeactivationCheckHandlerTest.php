<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Employee;

use App\Application\Employee\ExitDeactivationCheckHandler;
use App\Application\Employee\ExitDeactivationCheckMessage;
use App\Application\Scheduler\ScheduledJobConfigManagerInterface;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ExitDeactivationCheckHandler::class)]
final class ExitDeactivationCheckHandlerTest extends TestCase
{
    private EmployeeRepository&MockObject $employeeRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private ScheduledJobConfigManagerInterface&MockObject $jobConfig;
    private MockClock $clock;

    private Company $company;
    private Location $hq;

    protected function setUp(): void
    {
        $this->employeeRepository = $this->createMock(EmployeeRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->jobConfig = $this->createMock(ScheduledJobConfigManagerInterface::class);
        $this->clock = new MockClock('2026-05-15 07:00:00', 'UTC');

        $this->company = new Company('Acme GmbH');
        $this->hq = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
    }

    private function createHandler(): ExitDeactivationCheckHandler
    {
        return new ExitDeactivationCheckHandler(
            $this->employeeRepository,
            $this->entityManager,
            $this->jobConfig,
            $this->clock,
        );
    }

    private function createEmployeeWithUser(
        string $email,
        string $name,
        string $number,
        \DateTimeImmutable $joinedAt,
        ?\DateTimeImmutable $leftAt = null,
    ): Employee {
        $user = new User($this->company, $email, UserRole::Employee);

        return new Employee(
            company: $this->company,
            fullName: $name,
            employeeNumber: $number,
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: $joinedAt,
            user: $user,
            leftAt: $leftAt,
        );
    }

    #[Test]
    public function itSkipsAllWhenJobIsDisabled(): void
    {
        $this->jobConfig->method('isEnabled')->with(ExitDeactivationCheckHandler::JOB_NAME)->willReturn(false);
        $this->jobConfig->expects($this->once())
            ->method('markRun')
            ->with(ExitDeactivationCheckHandler::JOB_NAME, ScheduledJobRunStatus::Skipped);

        $this->employeeRepository->expects($this->never())->method('findExitedWithActiveUser');
        $this->entityManager->expects($this->never())->method('flush');

        ($this->createHandler())(new ExitDeactivationCheckMessage());
    }

    #[Test]
    public function itDeactivatesUsersForEmployeesWhoseExitDateHasPassed(): void
    {
        $this->jobConfig->method('isEnabled')->willReturn(true);

        $joinedAt = new \DateTimeImmutable('2023-01-01');
        $leftAt = new \DateTimeImmutable('2026-05-01'); // past

        $employee = $this->createEmployeeWithUser('alice@example.com', 'Alice Brown', 'E001', $joinedAt, $leftAt);
        $user = $employee->getUser();
        $this->assertNotNull($user);
        $this->assertTrue($user->isActive());

        $today = $this->clock->now()->setTime(0, 0);
        $this->employeeRepository
            ->expects($this->once())
            ->method('findExitedWithActiveUser')
            ->with($today)
            ->willReturn([$employee]);

        $this->entityManager->expects($this->once())->method('flush');

        $this->jobConfig->expects($this->once())
            ->method('markRun')
            ->with(ExitDeactivationCheckHandler::JOB_NAME, ScheduledJobRunStatus::Success);

        ($this->createHandler())(new ExitDeactivationCheckMessage());

        $this->assertFalse($user->isActive());
    }

    #[Test]
    public function itDeactivatesMultipleUsersInASingleSweep(): void
    {
        $this->jobConfig->method('isEnabled')->willReturn(true);

        $joinedAt = new \DateTimeImmutable('2023-01-01');
        $leftAt = new \DateTimeImmutable('2026-05-10');

        $emp1 = $this->createEmployeeWithUser('bob@example.com', 'Bob Smith', 'E002', $joinedAt, $leftAt);
        $emp2 = $this->createEmployeeWithUser('carol@example.com', 'Carol Jones', 'E003', $joinedAt, $leftAt);

        $today = $this->clock->now()->setTime(0, 0);
        $this->employeeRepository->method('findExitedWithActiveUser')->with($today)->willReturn([$emp1, $emp2]);

        $this->entityManager->expects($this->once())->method('flush');

        ($this->createHandler())(new ExitDeactivationCheckMessage());

        $this->assertFalse($emp1->getUser()?->isActive());
        $this->assertFalse($emp2->getUser()?->isActive());
    }

    #[Test]
    public function itFlushesNothingAndMarksSuccessWhenNoEmployeesAreDue(): void
    {
        $this->jobConfig->method('isEnabled')->willReturn(true);

        $this->employeeRepository->method('findExitedWithActiveUser')->willReturn([]);

        $this->entityManager->expects($this->never())->method('flush');

        $this->jobConfig->expects($this->once())
            ->method('markRun')
            ->with(ExitDeactivationCheckHandler::JOB_NAME, ScheduledJobRunStatus::Success);

        ($this->createHandler())(new ExitDeactivationCheckMessage());
    }

    #[Test]
    public function itMarksFailureAndRethrowsOnUnexpectedException(): void
    {
        $this->jobConfig->method('isEnabled')->willReturn(true);

        $boom = new \RuntimeException('DB gone');
        $this->employeeRepository->method('findExitedWithActiveUser')->willThrowException($boom);

        $this->jobConfig->expects($this->once())
            ->method('markRun')
            ->with(ExitDeactivationCheckHandler::JOB_NAME, ScheduledJobRunStatus::Failure, 'DB gone');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB gone');

        ($this->createHandler())(new ExitDeactivationCheckMessage());
    }
}
