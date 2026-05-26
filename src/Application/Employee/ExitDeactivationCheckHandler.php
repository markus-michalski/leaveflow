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

namespace App\Application\Employee;

use App\Application\Scheduler\ScheduledJobConfigManagerInterface;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily sweep that deactivates user accounts for employees whose exit
 * date has been reached but whose account was kept active at exit time
 * because the date was in the future (#81, #82).
 *
 * The repository query uses INNER JOIN on `e.user`, so every returned
 * employee has a non-null user. The null-safe operator (`?->`) is kept
 * because `Employee::getUser()` is typed `?User` — the PHP type system
 * cannot reflect the JOIN guarantee, so dropping `?->` would break
 * PHPStan Level 8. The call is never a no-op in practice.
 *
 * Flush is skipped entirely when nothing needs to change, keeping the
 * DB write-free on quiet days.
 */
#[AsMessageHandler]
final readonly class ExitDeactivationCheckHandler
{
    public const string JOB_NAME = 'exit-deactivation-check';

    public function __construct(
        private EmployeeRepository $employeeRepository,
        private EntityManagerInterface $entityManager,
        private ScheduledJobConfigManagerInterface $jobConfig,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExitDeactivationCheckMessage $message): void
    {
        if (!$this->jobConfig->isEnabled(self::JOB_NAME)) {
            $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Skipped);

            return;
        }

        try {
            // Clock is expected to run in UTC (matches Docker container default).
            // leftAt is a date-only column, so the <= comparison has a full-day
            // slack — an employee with leftAt = today is picked up at 07:00 UTC.
            $today = $this->clock->now()->setTime(0, 0);
            $employees = $this->employeeRepository->findExitedWithActiveUser($today);

            foreach ($employees as $employee) {
                $employee->getUser()?->deactivate();
            }

            if ([] !== $employees) {
                $this->entityManager->flush();
            }
        } catch (\Throwable $e) {
            try {
                $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Failure, $e->getMessage());
            } catch (\Throwable) {
                // Bookkeeping failure must not mask the original handler failure.
            }

            throw $e;
        }

        $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Success);
    }
}
