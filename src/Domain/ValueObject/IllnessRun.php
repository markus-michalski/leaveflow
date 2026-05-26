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

/**
 * A single, gapless stretch of illness-tracking absences.
 *
 * Calendar-day count, not working days — §3 EntgFG counts in calendar
 * days, and that's what the 6-week threshold maps onto. The threshold
 * itself lives on the handler (42 days), this VO is pure data.
 *
 * `startedOn` and `endsOn` are inclusive day boundaries; a single-day
 * illness has startedOn === endsOn and dayCount === 1.
 */
final readonly class IllnessRun
{
    public function __construct(
        public \DateTimeImmutable $startedOn,
        public \DateTimeImmutable $endsOn,
        public int $dayCount,
    ) {
        if ($endsOn < $startedOn) {
            throw new \InvalidArgumentException('IllnessRun.endsOn must not precede startedOn.');
        }
        if ($dayCount < 1) {
            throw new \InvalidArgumentException('IllnessRun.dayCount must be at least 1.');
        }
    }
}
