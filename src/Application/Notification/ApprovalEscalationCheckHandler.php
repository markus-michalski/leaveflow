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

namespace App\Application\Notification;

use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\NotificationType;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sweeps for Pending LeaveRequests that have exceeded their company's
 * approvalEscalationDays threshold and notifies all active admin users
 * of that company.
 *
 * Phase 8 deliberately uses admins-as-fallback rather than walking a
 * lead → deputy → admin chain. Rationale: LeaveFlow has no nested
 * department hierarchy, the original ApprovalRequested already went to
 * the lead/deputy via ApproverResolver, and admins are the documented
 * "last resort" backstop. A per-step chain walk can land in Phase 9 if
 * SMB feedback signals a need.
 *
 * Idempotent via LeaveRequest.escalationNotifiedAt — set once, no further
 * escalations on the same request.
 */
#[AsMessageHandler]
final readonly class ApprovalEscalationCheckHandler
{
    public const string JOB_NAME = 'approval-escalation-check';

    public function __construct(
        private LeaveRequestRepository $leaveRequestRepository,
        private UserRepository $userRepository,
        private NotificationDispatcherInterface $dispatcher,
        private EntityManagerInterface $entityManager,
        private \App\Application\Scheduler\ScheduledJobConfigManagerInterface $jobConfig,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ApprovalEscalationCheckMessage $message): void
    {
        if (!$this->jobConfig->isEnabled(self::JOB_NAME)) {
            $this->jobConfig->markRun(self::JOB_NAME, \App\Domain\Enum\ScheduledJobRunStatus::Skipped);

            return;
        }

        try {
            $now = $this->clock->now();
            $requests = $this->leaveRequestRepository->findPendingNeedingEscalation($now);

            // Cache admins per-company so a batch with many requests from the
            // same company doesn't re-query for each row.
            $adminsByCompanyId = [];

            foreach ($requests as $request) {
                $this->processRequest($request, $now, $adminsByCompanyId);
            }

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->jobConfig->markRun(self::JOB_NAME, \App\Domain\Enum\ScheduledJobRunStatus::Failure, $e->getMessage());

            throw $e;
        }

        $this->jobConfig->markRun(self::JOB_NAME, \App\Domain\Enum\ScheduledJobRunStatus::Success);
    }

    /**
     * @param array<int, list<\App\Domain\Entity\User>> $adminsByCompanyKey
     */
    private function processRequest(LeaveRequest $request, \DateTimeImmutable $now, array &$adminsByCompanyKey): void
    {
        $company = $request->getEmployee()->getCompany();
        // spl_object_id keys the cache by object identity — works for both
        // persisted entities (with IDs) and transient ones in unit tests.
        $cacheKey = spl_object_id($company);

        if (!isset($adminsByCompanyKey[$cacheKey])) {
            $adminsByCompanyKey[$cacheKey] = $this->userRepository->findActiveAdminsByCompany($company);
        }
        $admins = $adminsByCompanyKey[$cacheKey];

        if ([] === $admins) {
            // No admins to escalate to — keep the request unflagged so a
            // future sweep retries once an admin exists.
            return;
        }

        $payload = [
            'employeeName' => $request->getEmployee()->getFullName(),
            'absenceTypeName' => $request->getAbsenceType()->getName(),
            'startDate' => $request->getStartDate()->format('d.m.Y'),
            'endDate' => $request->getEndDate()->format('d.m.Y'),
            'daysWaiting' => $this->daysBetweenDates($request->getRequestedAt(), $now),
            'thresholdDays' => $company->getApprovalEscalationDays(),
        ];

        foreach ($admins as $admin) {
            $this->dispatcher->dispatch(
                type: NotificationType::EscalationTriggered,
                recipient: $admin,
                payload: $payload,
                relatedEntityType: LeaveRequest::class,
                relatedEntityId: $request->getId(),
            );
        }

        $request->markEscalationNotified($now);
    }

    /**
     * Calendar-day difference, timezone-agnostic — see
     * EntitlementExpiryCheckHandler for rationale.
     */
    private function daysBetweenDates(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $utc = new \DateTimeZone('UTC');
        $fromUtc = new \DateTimeImmutable($from->format('Y-m-d'), $utc);
        $toUtc = new \DateTimeImmutable($to->format('Y-m-d'), $utc);

        return (int) $fromUtc->diff($toUtc)->days;
    }
}
