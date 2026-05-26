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

namespace App\Application\Import;

use App\Domain\Entity\Employee;

/**
 * Per-row outcome of {@see EmployeeImportValidator::validate}.
 *
 * Three states:
 * - valid: errors=[], employee built but NOT yet persisted
 * - invalid: errors populated, employee=null
 * - committed: only set after a successful commit pass
 *
 * The employee instance for valid rows is the same object the commit
 * pass would persist — pre-built so the preview shows the resolved
 * Location/User/Department references the admin can sanity-check
 * before pulling the trigger.
 */
final class EmployeeImportRowResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly EmployeeImportRow $row,
        public readonly array $errors = [],
        public ?Employee $employee = null,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}
