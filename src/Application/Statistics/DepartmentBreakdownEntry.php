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
 * Per-department aggregate row in the admin statistics dashboard.
 *
 * When `hidden` is true the department's headcount falls below the
 * anonymity threshold (k-anonymity, DSGVO §22 BDSG). All metric fields
 * are null in that case and the UI must render a placeholder instead
 * of leaking a one- or two-person department's leave balance.
 *
 * The "Ohne Abteilung" bucket — employees with no department assigned —
 * is represented as a regular entry with `name` localized via the caller;
 * the calculator neither knows nor cares about that distinction.
 */
final readonly class DepartmentBreakdownEntry
{
    public function __construct(
        public string $name,
        public int $employeeCount,
        public bool $hidden,
        public ?float $totalGrantedHours,
        public ?float $totalUsedHours,
        public ?float $utilizationPercent,
    ) {
    }
}
