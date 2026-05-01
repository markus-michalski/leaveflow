<?php

declare(strict_types=1);

namespace App\Application\Calendar;

use App\Domain\Entity\BlackoutPeriod;

/**
 * Thrown when a leave request's date range overlaps one or more BlackoutPeriods
 * that apply to the requesting employee (company-wide or matching department).
 *
 * Surfaces the offending blackouts so the controller can render a precise
 * error message ("Werksferien", "Release-Freeze", ...) without re-querying.
 */
final class BlackoutPeriodViolationException extends \DomainException
{
    /**
     * @param list<BlackoutPeriod> $blackoutPeriods
     */
    public function __construct(
        public readonly array $blackoutPeriods,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<BlackoutPeriod> $blackouts
     */
    public static function forBlackouts(array $blackouts): self
    {
        $reasons = array_map(static fn (BlackoutPeriod $b): string => $b->getReason(), $blackouts);
        $message = \sprintf(
            'Leave request overlaps blackout period(s): %s.',
            implode(', ', $reasons),
        );

        return new self($blackouts, $message);
    }
}
