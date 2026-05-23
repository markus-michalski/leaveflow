<?php

declare(strict_types=1);

namespace App\Application\Api\Employee;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /api/v1/employees.
 * Deserialized from the JSON request body via #[MapRequestPayload].
 */
final readonly class CreateEmployeeRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 150)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public string $employeeNumber,
        #[Assert\NotNull]
        #[Assert\Positive]
        public int $locationId,
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $joinedAt,
        #[Assert\Positive]
        public float $weeklyHours = 40.0,
        public string $role = 'employee',
    ) {
    }
}
