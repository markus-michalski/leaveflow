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
