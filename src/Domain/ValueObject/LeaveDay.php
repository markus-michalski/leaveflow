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

namespace App\Domain\ValueObject;

use App\Domain\Enum\ExclusionReason;
use App\Domain\Enum\LeaveDayStatus;

/**
 * A single day inside a LeaveCalculator result.
 *
 * Working/HalfDay days carry hours and no reason; Excluded days carry 0 hours
 * and a concrete reason. Constructor invariants prevent nonsensical combos so
 * consumers can match on (status, reason) safely.
 */
final readonly class LeaveDay
{
    public function __construct(
        public \DateTimeImmutable $date,
        public float $hours,
        public LeaveDayStatus $status,
        public ?ExclusionReason $reason = null,
    ) {
        if ($hours < 0.0) {
            throw new \InvalidArgumentException('LeaveDay.hours must not be negative.');
        }

        $isExcluded = LeaveDayStatus::Excluded === $status;

        if ($isExcluded && null === $reason) {
            throw new \InvalidArgumentException('Excluded LeaveDay requires a reason.');
        }
        if (!$isExcluded && null !== $reason) {
            throw new \InvalidArgumentException('Non-excluded LeaveDay must not carry a reason.');
        }
        if ($isExcluded && 0.0 !== $hours) {
            throw new \InvalidArgumentException('Excluded LeaveDay must have 0.0 hours.');
        }
        if (LeaveDayStatus::Working === $status && 0.0 === $hours) {
            throw new \InvalidArgumentException('Working LeaveDay must have hours > 0.');
        }
        if (LeaveDayStatus::HalfDay === $status && 0.0 === $hours) {
            throw new \InvalidArgumentException('HalfDay LeaveDay must have hours > 0.');
        }
    }
}
