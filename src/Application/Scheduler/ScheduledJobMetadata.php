<?php

declare(strict_types=1);

namespace App\Application\Scheduler;

/**
 * Static metadata for one scheduled job (#35 phase 3).
 *
 * The DB row in scheduled_job_configs only carries runtime state
 * (toggle + last-run bookkeeping). Cadence + display labels live in
 * code because they're release-tied: redeploy to change the
 * cron expression, not a DB UPDATE.
 *
 * The label key is resolved through the translator; admin UI passes
 * payload-free translation calls so DE+EN are owned by yaml.
 */
final readonly class ScheduledJobMetadata
{
    public function __construct(
        public string $name,
        public string $cronExpression,
        public string $labelKey,
        public string $descriptionKey,
    ) {
    }
}
