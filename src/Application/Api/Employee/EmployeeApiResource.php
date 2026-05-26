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

namespace App\Application\Api\Employee;

use App\Domain\Entity\Employee;

/**
 * Read-only API response DTO for an Employee.
 * Exposes only the fields relevant for external integrations.
 */
final readonly class EmployeeApiResource
{
    private function __construct(
        public int $id,
        public string $name,
        public string $employeeNumber,
        public ?string $email,
        public int $locationId,
        public string $locationName,
        public string $joinedAt,
        public ?string $leftAt,
        public float $weeklyHours,
        public ?int $userId,
    ) {
    }

    public static function fromEntity(Employee $employee): self
    {
        return new self(
            id: (int) $employee->getId(),
            name: $employee->getFullName(),
            employeeNumber: $employee->getEmployeeNumber(),
            email: $employee->getUser()?->getEmail(),
            locationId: (int) $employee->getLocation()->getId(),
            locationName: $employee->getLocation()->getName(),
            joinedAt: $employee->getJoinedAt()->format('Y-m-d'),
            leftAt: $employee->getLeftAt()?->format('Y-m-d'),
            weeklyHours: $employee->getWorkSchedule()->weeklyHours(),
            userId: $employee->getUser()?->getId(),
        );
    }
}
