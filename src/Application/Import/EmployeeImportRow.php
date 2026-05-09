<?php

declare(strict_types=1);

namespace App\Application\Import;

/**
 * One parsed CSV row from the employee import (Phase 9 CSV-Import).
 *
 * Stays a dumb DTO — no validation, no normalization. The parser hands
 * raw trimmed strings, the validator decides what's valid. Optional
 * columns map to null (not empty string) so downstream code can use
 * `??` for defaults.
 *
 * Line number is 1-based and points at the source CSV row (header is
 * line 1, first data row is line 2). Used for "row 7: weeklyHours
 * must be > 0" style error messages so admins can correct in their
 * spreadsheet without re-counting.
 */
final readonly class EmployeeImportRow
{
    public function __construct(
        public int $lineNumber,
        public string $fullName,
        public string $employeeNumber,
        public string $locationName,
        public string $weeklyHours,
        public string $workingDays,
        public string $joinedAt,
        public ?string $leftAt = null,
        public ?string $userEmail = null,
        public ?string $departmentName = null,
    ) {
    }
}
