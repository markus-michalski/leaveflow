<?php

declare(strict_types=1);

namespace App\Application\Api\Employee;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for PATCH /api/v1/employees/{id}.
 * All fields are optional — only non-null values are applied.
 */
final readonly class UpdateEmployeeRequest
{
    public function __construct(
        #[Assert\Length(max: 150)]
        public ?string $name = null,
        #[Assert\Positive]
        public ?int $locationId = null,
        #[Assert\Positive]
        public ?float $weeklyHours = null,
    ) {
    }
}
