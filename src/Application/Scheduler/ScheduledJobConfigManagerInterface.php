<?php

declare(strict_types=1);

namespace App\Application\Scheduler;

use App\Domain\Enum\ScheduledJobRunStatus;

/**
 * Handler-facing API for the scheduled-job toggle layer.
 *
 * Extracted purely for testability — handlers need to mock the manager
 * without wiring up the repository + EntityManager + clock pipeline.
 */
interface ScheduledJobConfigManagerInterface
{
    public function isEnabled(string $jobName): bool;

    public function markRun(string $jobName, ScheduledJobRunStatus $status, ?string $error = null): void;

    public function setEnabled(string $jobName, bool $enabled): void;
}
