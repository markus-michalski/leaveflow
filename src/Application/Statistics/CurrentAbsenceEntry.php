<?php

declare(strict_types=1);

namespace App\Application\Statistics;

/**
 * "Currently away" row on the dashboard. Color is the AbsenceType hex
 * so the template can render a small dot/badge without hitting the
 * entity again.
 */
final readonly class CurrentAbsenceEntry
{
    public function __construct(
        public int $requestId,
        public string $employeeName,
        public string $absenceTypeName,
        public string $absenceTypeColor,
        public \DateTimeImmutable $endDate,
        public bool $endsToday,
    ) {
    }
}
