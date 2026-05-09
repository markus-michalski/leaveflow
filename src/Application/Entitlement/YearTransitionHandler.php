<?php

declare(strict_types=1);

namespace App\Application\Entitlement;

use App\Application\Scheduler\ScheduledJobConfigManagerInterface;
use App\Domain\Enum\ScheduledJobRunStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Wraps {@see YearTransitionService::transition} for the scheduler — closes
 * the gap from #35 where forgetting the manual `app:entitlement:year-transition`
 * command silently lost employees their carryover entitlements.
 *
 * Schedule: January 1st at 01:00, source year = current year - 1. Idempotent
 * by design — the underlying service skips employees that already have a
 * carryover row for the target year, so accidental re-runs are harmless.
 *
 * Toggleable via the ScheduledJobConfig row named `year-transition`. When
 * disabled the handler short-circuits and records a Skipped run so admins
 * still see the trigger in the run log.
 *
 * Logs the per-status counts so admins reviewing the messenger transport log
 * can see at a glance whether a run created entries, found nothing, or
 * skipped duplicates.
 */
#[AsMessageHandler]
final readonly class YearTransitionHandler
{
    public const string JOB_NAME = 'year-transition';

    public function __construct(
        private YearTransitionServiceInterface $service,
        private ScheduledJobConfigManagerInterface $jobConfig,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(YearTransitionMessage $message): void
    {
        if (!$this->jobConfig->isEnabled(self::JOB_NAME)) {
            $this->logger->info('Year-transition sweep skipped (toggle disabled).');
            $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Skipped);

            return;
        }

        $sourceYear = (int) $this->clock->now()->format('Y') - 1;

        $this->logger->info('Year-transition sweep starting.', ['sourceYear' => $sourceYear]);

        try {
            $report = $this->service->transition($sourceYear);
        } catch (\Throwable $e) {
            $this->logger->error('Year-transition sweep failed.', [
                'sourceYear' => $sourceYear,
                'error' => $e->getMessage(),
            ]);
            $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Failure, $e->getMessage());

            throw $e;
        }

        $created = 0;
        $skippedExists = 0;
        $skippedEmpty = 0;
        foreach ($report as $entry) {
            match ($entry->status) {
                YearTransitionStatus::Created => ++$created,
                YearTransitionStatus::SkippedAlreadyExists => ++$skippedExists,
                YearTransitionStatus::SkippedEmptyBalance => ++$skippedEmpty,
            };
        }

        $this->logger->info('Year-transition sweep finished.', [
            'sourceYear' => $sourceYear,
            'created' => $created,
            'skippedAlreadyExists' => $skippedExists,
            'skippedEmptyBalance' => $skippedEmpty,
        ]);

        $this->jobConfig->markRun(self::JOB_NAME, ScheduledJobRunStatus::Success);
    }
}
