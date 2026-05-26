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

use App\Domain\Enum\Weekday;
use Doctrine\ORM\Mapping as ORM;

/**
 * A weekly work pattern: total weekly hours + per-weekday distribution.
 *
 * Persisted as a Doctrine embeddable. Use named constructors instead of `new`.
 */
#[ORM\Embeddable]
final class WorkSchedule
{
    private const float SUM_EPSILON = 0.01;

    /**
     * Map of Weekday::value (1..7) => hours. Non-working days are absent.
     *
     * @var array<int, float>
     */
    #[ORM\Column(name: 'hours_per_day_json', type: 'json')]
    private array $hoursPerDay;

    /**
     * @param array<int, float> $hoursPerDay
     */
    private function __construct(
        // float (DOUBLE in MariaDB) over decimal(5,2): hours aren't financial,
        // the codebase already tolerates SUM_EPSILON drift across WorkSchedule,
        // LeaveEntitlement, and EntitlementConsumer. decimal would force PHP to
        // hold the value as string, mismatching the float property type and
        // tripping doctrine:schema:validate (#16).
        #[ORM\Column(name: 'weekly_hours', type: 'float')]
        private float $weeklyHours,
        array $hoursPerDay,
    ) {
        $this->hoursPerDay = $hoursPerDay;
    }

    /**
     * Distribute weekly hours evenly across the given working days.
     *
     * @param list<Weekday> $workingDays
     */
    public static function autoDistribute(float $weeklyHours, array $workingDays): self
    {
        self::assertPositive($weeklyHours);
        if ([] === $workingDays) {
            throw new \InvalidArgumentException('WorkSchedule requires at least one working day.');
        }
        self::assertNoDuplicates($workingDays);

        $hoursEach = $weeklyHours / \count($workingDays);
        $map = [];
        foreach ($workingDays as $day) {
            $map[$day->value] = $hoursEach;
        }

        return new self($weeklyHours, $map);
    }

    /**
     * Manual per-day distribution. Keys must be valid Weekday::value (1..7).
     * Zero-hour entries are dropped (not a working day).
     *
     * @param array<int, float> $hoursPerDay
     */
    public static function manual(float $weeklyHours, array $hoursPerDay): self
    {
        self::assertPositive($weeklyHours);

        $normalized = [];
        foreach ($hoursPerDay as $dayValue => $hours) {
            if (null === Weekday::tryFrom($dayValue)) {
                throw new \InvalidArgumentException(\sprintf('invalid weekday value: %d', $dayValue));
            }
            if ($hours < 0.0) {
                throw new \InvalidArgumentException('Hours per day cannot be negative.');
            }
            if (0.0 === $hours) {
                continue;
            }
            $normalized[$dayValue] = $hours;
        }

        if ([] === $normalized) {
            throw new \InvalidArgumentException('WorkSchedule requires at least one working day.');
        }

        $sum = array_sum($normalized);
        if (abs($sum - $weeklyHours) > self::SUM_EPSILON) {
            throw new \InvalidArgumentException(\sprintf('Sum of daily hours (%.2f) does not match weekly hours (%.2f).', $sum, $weeklyHours));
        }

        return new self($weeklyHours, $normalized);
    }

    public static function standardFullTime(): self
    {
        return self::autoDistribute(40.0, [
            Weekday::Monday,
            Weekday::Tuesday,
            Weekday::Wednesday,
            Weekday::Thursday,
            Weekday::Friday,
        ]);
    }

    public function weeklyHours(): float
    {
        return $this->weeklyHours;
    }

    public function hoursForDay(Weekday $day): float
    {
        return $this->hoursPerDay[$day->value] ?? 0.0;
    }

    public function isWorkingDay(Weekday $day): bool
    {
        return isset($this->hoursPerDay[$day->value]) && $this->hoursPerDay[$day->value] > 0.0;
    }

    /**
     * @return list<Weekday>
     */
    public function workingDays(): array
    {
        $days = array_keys($this->hoursPerDay);
        sort($days);

        return array_map(static fn (int $v): Weekday => Weekday::from($v), $days);
    }

    public function equals(self $other): bool
    {
        if (abs($this->weeklyHours - $other->weeklyHours) > self::SUM_EPSILON) {
            return false;
        }
        if (array_keys($this->hoursPerDay) !== array_keys($other->hoursPerDay)) {
            $a = $this->hoursPerDay;
            $b = $other->hoursPerDay;
            ksort($a);
            ksort($b);
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
        }
        foreach ($this->hoursPerDay as $day => $hours) {
            if (!isset($other->hoursPerDay[$day])) {
                return false;
            }
            if (abs($hours - $other->hoursPerDay[$day]) > self::SUM_EPSILON) {
                return false;
            }
        }

        return true;
    }

    private static function assertPositive(float $weeklyHours): void
    {
        if ($weeklyHours <= 0.0) {
            throw new \InvalidArgumentException('Weekly hours must be greater than zero.');
        }
    }

    /**
     * @param list<Weekday> $workingDays
     */
    private static function assertNoDuplicates(array $workingDays): void
    {
        $seen = [];
        foreach ($workingDays as $day) {
            if (isset($seen[$day->value])) {
                throw new \InvalidArgumentException('WorkSchedule contains duplicate working days.');
            }
            $seen[$day->value] = true;
        }
    }
}
