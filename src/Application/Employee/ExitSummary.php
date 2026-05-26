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

namespace App\Application\Employee;

use App\Domain\Enum\ExitLeaveHandling;

/**
 * Result of the employee exit workflow.
 *
 * Passed to the controller so it can present an informative flash message
 * without the controller itself having to know the business rules.
 */
final readonly class ExitSummary
{
    private const float BALANCE_EPSILON = 0.01;

    public function __construct(
        public float $totalRemainingHours,
        public ExitLeaveHandling $exitLeaveHandling,
        public \DateTimeImmutable $exitDate,
        public bool $userDeactivated,
    ) {
    }

    public function hasRemainingBalance(): bool
    {
        return $this->totalRemainingHours > self::BALANCE_EPSILON;
    }
}
