<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Enum\NotificationType;
use App\Domain\Repository\LeaveEntitlementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sweeps for LeaveEntitlements whose expiresAt falls within 30 days and
 * dispatches an EntitlementExpiringSoon notification per recipient.
 *
 * Idempotent: each entitlement carries an `expiryWarningSentAt` timestamp
 * that the handler sets after dispatching. The repository excludes
 * entitlements that already have it set, so the daily sweep is safe to
 * re-run without spamming users.
 *
 * Skips entitlements whose employee has no User account (Phase 2 retention
 * case for ex-employees) without flipping the timestamp — re-linking a User
 * later still triggers the warning.
 */
#[AsMessageHandler]
final readonly class EntitlementExpiryCheckHandler
{
    public const string JOB_NAME = 'entitlement-expiry-check';

    private const int WARNING_WINDOW_DAYS = 30;

    public function __construct(
        private LeaveEntitlementRepository $entitlementRepository,
        private NotificationDispatcherInterface $dispatcher,
        private EntityManagerInterface $entityManager,
        private \App\Application\Scheduler\ScheduledJobConfigManagerInterface $jobConfig,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EntitlementExpiryCheckMessage $message): void
    {
        if (!$this->jobConfig->isEnabled(self::JOB_NAME)) {
            $this->jobConfig->markRun(self::JOB_NAME, \App\Domain\Enum\ScheduledJobRunStatus::Skipped);

            return;
        }

        try {
            $today = $this->clock->now()->setTime(0, 0);
            $entitlements = $this->entitlementRepository->findExpiringWithoutWarning(
                $today,
                self::WARNING_WINDOW_DAYS,
            );

            foreach ($entitlements as $entitlement) {
                $this->processEntitlement($entitlement, $today);
            }

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->jobConfig->markRun(self::JOB_NAME, \App\Domain\Enum\ScheduledJobRunStatus::Failure, $e->getMessage());

            throw $e;
        }

        $this->jobConfig->markRun(self::JOB_NAME, \App\Domain\Enum\ScheduledJobRunStatus::Success);
    }

    private function processEntitlement(LeaveEntitlement $entitlement, \DateTimeImmutable $today): void
    {
        $recipient = $entitlement->getEmployee()->getUser();
        if (null === $recipient) {
            return;
        }

        $expiresAt = $entitlement->getExpiresAt();
        if (null === $expiresAt) {
            return;
        }

        $daysRemaining = $this->daysBetweenDates($today, $expiresAt);

        $this->dispatcher->dispatch(
            type: NotificationType::EntitlementExpiringSoon,
            recipient: $recipient,
            payload: [
                'daysRemaining' => $daysRemaining,
                'expiresAt' => $expiresAt->format('d.m.Y'),
                'hoursRemaining' => number_format($entitlement->getHoursRemaining(), 2, ',', '.'),
                'year' => $entitlement->getYear(),
                'entitlementType' => $entitlement->getType()->value,
            ],
            relatedEntityType: LeaveEntitlement::class,
            relatedEntityId: $entitlement->getId(),
        );

        $entitlement->markExpiryWarningSent($this->clock->now());
    }

    /**
     * Calendar-day difference, timezone-agnostic. Doctrine returns DATE
     * columns at midnight in PHP's default tz, while ClockInterface uses
     * UTC — naive `diff()->days` between the two leaks DST/offset hours and
     * shifts the count by one. Project both onto UTC midnight first.
     */
    private function daysBetweenDates(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $utc = new \DateTimeZone('UTC');
        $fromUtc = new \DateTimeImmutable($from->format('Y-m-d'), $utc);
        $toUtc = new \DateTimeImmutable($to->format('Y-m-d'), $utc);

        return (int) $fromUtc->diff($toUtc)->days;
    }
}
