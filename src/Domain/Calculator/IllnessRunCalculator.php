<?php

declare(strict_types=1);

namespace App\Domain\Calculator;

use App\Domain\Entity\LeaveRequest;
use App\Domain\ValueObject\IllnessRun;

/**
 * Pure calculator that finds the active illness run for an employee.
 *
 * "Active" means: the run that today (or yesterday) belongs to. Past
 * runs that ended more than one calendar day ago are ignored — the
 * IllnessAlertCheckHandler relies on idempotency through the
 * `illness_alerts` table for those, and an old run reaching threshold
 * means the alert already fired on a prior sweep.
 *
 * Inputs are LeaveRequests already filtered to the illness-tracking
 * AbsenceType — the calculator does not re-check the type flag, so it
 * stays pure and easy to test against fabricated requests.
 *
 * Algorithm:
 * 1. Clamp request end dates to `asOf` (planned future days don't count).
 * 2. Skip fully future requests.
 * 3. Sort + merge adjacent / overlapping ranges. Adjacent = next start is
 *    exactly previous end + 1 day. Any longer gap breaks the run, even
 *    when the total summed length would exceed the threshold.
 * 4. Pick the merged range that covers `asOf` or `asOf - 1 day` (the
 *    "Tag 43" tolerance — a sweep on the day after recovery still alarms
 *    if the run was long enough).
 * 5. Below {@see self::THRESHOLD_DAYS} → null. Otherwise return the run.
 */
final class IllnessRunCalculator
{
    public const int THRESHOLD_DAYS = 42;

    /**
     * @param list<LeaveRequest> $illnessRequests
     */
    public function findActiveRun(array $illnessRequests, \DateTimeImmutable $asOf): ?IllnessRun
    {
        $asOf = $asOf->setTime(0, 0);

        $ranges = [];
        foreach ($illnessRequests as $request) {
            $start = $request->getStartDate()->setTime(0, 0);
            $end = $request->getEndDate()->setTime(0, 0);

            if ($start > $asOf) {
                continue;
            }
            if ($end > $asOf) {
                $end = $asOf;
            }
            $ranges[] = ['start' => $start, 'end' => $end];
        }

        if ([] === $ranges) {
            return null;
        }

        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $merged = [];
        foreach ($ranges as $range) {
            if ([] === $merged) {
                $merged[] = $range;
                continue;
            }
            $last = &$merged[\count($merged) - 1];
            $adjacencyBoundary = $last['end']->modify('+1 day');
            if ($range['start'] <= $adjacencyBoundary) {
                if ($range['end'] > $last['end']) {
                    $last['end'] = $range['end'];
                }
            } else {
                $merged[] = $range;
            }
            unset($last);
        }

        $activeWindowStart = $asOf->modify('-1 day');
        $active = null;
        foreach ($merged as $range) {
            if ($range['end'] >= $activeWindowStart) {
                $active = $range;
            }
        }

        if (null === $active) {
            return null;
        }

        $days = $this->daysBetween($active['start'], $active['end']);
        if ($days < self::THRESHOLD_DAYS) {
            return null;
        }

        return new IllnessRun($active['start'], $active['end'], $days);
    }

    private function daysBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        // Project both onto UTC midnight so DST shifts don't bleed an
        // off-by-one into the diff (same fix as the
        // EntitlementExpiryCheckHandler day arithmetic).
        $utc = new \DateTimeZone('UTC');
        $startUtc = new \DateTimeImmutable($start->format('Y-m-d'), $utc);
        $endUtc = new \DateTimeImmutable($end->format('Y-m-d'), $utc);

        return (int) $startUtc->diff($endUtc)->days + 1;
    }
}
