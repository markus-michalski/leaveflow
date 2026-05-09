<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Result of the most recent scheduled-job run (#35 phase 2).
 *
 * - Success: handler completed without throwing.
 * - Failure: handler threw an exception (caller stores the message).
 * - Skipped: handler was reached but the toggle was disabled — bookkeeping
 *   so admins can distinguish "never ran" (lastRunAt is null) from
 *   "fired but intentionally short-circuited".
 */
enum ScheduledJobRunStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';

    public function label(): string
    {
        return 'admin.scheduled_jobs.status.'.$this->value;
    }
}
