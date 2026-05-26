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
