<?php

declare(strict_types=1);

namespace App\Application\Scheduler;

use App\Application\Entitlement\YearTransitionHandler;
use App\Application\Notification\ApprovalEscalationCheckHandler;
use App\Application\Notification\EntitlementExpiryCheckHandler;
use App\Application\Notification\IllnessAlertCheckHandler;

/**
 * Source of truth for "which scheduled jobs exist" in the admin UI (#35).
 *
 * Symfony Scheduler defines its tasks via attribute-based providers
 * (NotificationSchedule + MaintenanceSchedule), but doesn't expose them
 * uniformly for listing. Mirroring the registration here is fine: adding
 * a new scheduled job is already a multi-file change (Message + Handler +
 * Schedule entry), one more line here is cheap and keeps the admin UI
 * working without runtime introspection of the messenger transport.
 *
 * If the list grows past ~10 entries, build a CompilerPass that scans
 * #[AsScheduledJob] attributes — but for v1 the explicit list reads
 * better than the indirection.
 */
final readonly class ScheduledJobRegistry
{
    /**
     * @return list<ScheduledJobMetadata>
     */
    public function all(): array
    {
        return [
            new ScheduledJobMetadata(
                name: YearTransitionHandler::JOB_NAME,
                cronExpression: '0 1 1 1 *',
                labelKey: 'admin.scheduled_jobs.job.year_transition.label',
                descriptionKey: 'admin.scheduled_jobs.job.year_transition.description',
            ),
            new ScheduledJobMetadata(
                name: EntitlementExpiryCheckHandler::JOB_NAME,
                cronExpression: '0 3 * * *',
                labelKey: 'admin.scheduled_jobs.job.entitlement_expiry_check.label',
                descriptionKey: 'admin.scheduled_jobs.job.entitlement_expiry_check.description',
            ),
            new ScheduledJobMetadata(
                name: ApprovalEscalationCheckHandler::JOB_NAME,
                cronExpression: '0 * * * *',
                labelKey: 'admin.scheduled_jobs.job.approval_escalation_check.label',
                descriptionKey: 'admin.scheduled_jobs.job.approval_escalation_check.description',
            ),
            new ScheduledJobMetadata(
                name: IllnessAlertCheckHandler::JOB_NAME,
                cronExpression: '0 6 * * *',
                labelKey: 'admin.scheduled_jobs.job.illness_alert_check.label',
                descriptionKey: 'admin.scheduled_jobs.job.illness_alert_check.description',
            ),
        ];
    }
}
