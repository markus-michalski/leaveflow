<?php

declare(strict_types=1);

namespace App\Application\Statistics;

/**
 * Single overdue-pending row for the action list. The dashboard does not
 * surface a "decide now" button — the manager approval queue already has
 * the workflow buttons; the dashboard just points to the entry.
 */
final readonly class OverduePendingEntry
{
    public function __construct(
        public int $requestId,
        public string $employeeName,
        public string $absenceTypeName,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public \DateTimeImmutable $requestedAt,
        public int $daysWaiting,
    ) {
    }
}
