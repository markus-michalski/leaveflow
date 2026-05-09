<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Calculator\IllnessRunCalculator;
use App\Domain\Entity\Employee;
use App\Domain\Entity\IllnessAlert;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\IllnessAlertRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\Repository\UserRepository;
use App\Domain\ValueObject\IllnessRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily sweep that detects employees who have crossed the 6-week
 * illness threshold (§3 EntgFG: 42 consecutive calendar days) and
 * notifies the department lead — with an Admin fallback if no
 * department lead is available.
 *
 * Idempotency: a row in `illness_alerts` keyed by (employee,
 * periodStartedOn) prevents the same illness run from triggering more
 * than one alert. The same person catching a new illness later that
 * starts a fresh run will trigger a new alert.
 *
 * Recipient resolution mirrors {@see \App\Application\Approval\ApproverResolver}
 * but works on Employee directly since there is no LeaveRequest in
 * play here. Cross-company sweep — admins-by-company are cached to
 * avoid n+1 lookups when the same company has many employees.
 */
#[AsMessageHandler]
final readonly class IllnessAlertCheckHandler
{
    public const string JOB_NAME = 'illness-alert-check';

    public function __construct(
        private EmployeeRepository $employeeRepository,
        private LeaveRequestRepository $leaveRequestRepository,
        private IllnessAlertRepository $illnessAlertRepository,
        private UserRepository $userRepository,
        private IllnessRunCalculator $calculator,
        private NotificationDispatcherInterface $dispatcher,
        private EntityManagerInterface $entityManager,
        private \App\Application\Scheduler\ScheduledJobConfigManagerInterface $jobConfig,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(IllnessAlertCheckMessage $message): void
    {
        if (!$this->jobConfig->isEnabled(self::JOB_NAME)) {
            $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Skipped);

            return;
        }

        try {
            $today = $this->clock->now()->setTime(0, 0);
            $employees = $this->employeeRepository->findAllActive($today);

            /** @var array<int, list<User>> $adminsByCompanyKey */
            $adminsByCompanyKey = [];

            foreach ($employees as $employee) {
                $this->processEmployee($employee, $today, $adminsByCompanyKey);
            }

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Failure, $e->getMessage());

            throw $e;
        }

        $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Success);
    }

    /**
     * @param array<int, list<User>> $adminsByCompanyKey
     */
    private function processEmployee(Employee $employee, \DateTimeImmutable $today, array &$adminsByCompanyKey): void
    {
        $requests = $this->leaveRequestRepository->findIllnessRequestsForEmployee($employee);
        $run = $this->calculator->findActiveRun($requests, $today);
        if (null === $run) {
            return;
        }

        if ($this->illnessAlertRepository->existsForEmployeePeriod($employee, $run->startedOn)) {
            return;
        }

        $recipients = $this->resolveRecipients($employee, $today, $adminsByCompanyKey);
        if ([] === $recipients) {
            // No one to notify — leave alert un-recorded so a future
            // sweep retries once a lead/admin exists.
            return;
        }

        $payload = $this->buildPayload($employee, $run);
        foreach ($recipients as $recipient) {
            $this->dispatcher->dispatch(
                type: NotificationType::IllnessSixWeekAlert,
                recipient: $recipient,
                payload: $payload,
                relatedEntityType: Employee::class,
                relatedEntityId: $employee->getId(),
            );
        }

        $this->entityManager->persist(new IllnessAlert(
            employee: $employee,
            periodStartedOn: $run->startedOn,
            daysCount: $run->dayCount,
            alertedAt: $this->clock->now(),
        ));
    }

    /**
     * Department lead first, then deputy, then all active company admins.
     * The handler relies on Symfony's role hierarchy treating ROLE_ADMIN
     * as a superset of ROLE_MANAGER, so admin-only fallback recipients
     * still match the IllnessSixWeekAlert's required role.
     *
     * @param array<int, list<User>> $adminsByCompanyKey
     *
     * @return list<User>
     */
    private function resolveRecipients(Employee $employee, \DateTimeImmutable $today, array &$adminsByCompanyKey): array
    {
        $department = $employee->getDepartment();
        if (null !== $department && $department->isActive()) {
            $lead = $department->getLead();
            if (null !== $lead && $lead !== $employee && $lead->isActiveOn($today)) {
                $leadUser = $lead->getUser();
                if (null !== $leadUser) {
                    return [$leadUser];
                }
            }
            $deputy = $department->getDeputy();
            if (null !== $deputy && $deputy !== $employee && $deputy->isActiveOn($today)) {
                $deputyUser = $deputy->getUser();
                if (null !== $deputyUser) {
                    return [$deputyUser];
                }
            }
        }

        $company = $employee->getCompany();
        $cacheKey = spl_object_id($company);
        if (!isset($adminsByCompanyKey[$cacheKey])) {
            $adminsByCompanyKey[$cacheKey] = $this->userRepository->findActiveAdminsByCompany($company);
        }

        return $adminsByCompanyKey[$cacheKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Employee $employee, IllnessRun $run): array
    {
        return [
            'employeeName' => $employee->getFullName(),
            'employeeNumber' => $employee->getEmployeeNumber(),
            'periodStartedOn' => $run->startedOn->format('d.m.Y'),
            'periodEndsOn' => $run->endsOn->format('d.m.Y'),
            'daysCount' => $run->dayCount,
            'thresholdDays' => IllnessRunCalculator::THRESHOLD_DAYS,
        ];
    }
}
