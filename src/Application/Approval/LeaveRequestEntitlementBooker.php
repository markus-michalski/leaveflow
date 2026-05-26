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

namespace App\Application\Approval;

use App\Application\Entitlement\EntitlementConsumer;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Books (consume) and refunds (release) entitlement hours for a LeaveRequest
 * on approval state-machine transitions.
 *
 * Works per calendar year: multi-year requests are split by the year of each
 * LeaveRequestDay's date and each slice is consumed against that year's
 * entitlement pool. This matches the preflight-balance check in
 * LeaveRequestService which enforces per-year sufficiency at create time.
 *
 * Non-deducting absence types (Krankheit, Sonderurlaub) short-circuit to a
 * no-op so callers don't need an outer guard.
 */
final readonly class LeaveRequestEntitlementBooker implements LeaveRequestEntitlementBookerInterface
{
    public function __construct(
        private LeaveEntitlementRepository $entitlementRepository,
        private EntitlementConsumer $consumer,
        private ClockInterface $clock,
    ) {
    }

    public function consume(LeaveRequest $request): void
    {
        $absenceType = $request->getAbsenceType();
        if (!$absenceType->deductsFromLeave()) {
            return;
        }

        $bucket = $absenceType->getRequiredBucket();
        $asOf = $this->clock->now();
        foreach ($this->hoursByYear($request) as $year => $hours) {
            $entitlements = $this->entitlementRepository->findByEmployeeAndYear($request->getEmployee(), $year);
            $this->consumer->consume($this->filterByBucket($entitlements, $bucket), $hours, $asOf);
        }
    }

    public function release(LeaveRequest $request): void
    {
        $absenceType = $request->getAbsenceType();
        if (!$absenceType->deductsFromLeave()) {
            return;
        }

        $bucket = $absenceType->getRequiredBucket();
        foreach ($this->hoursByYear($request) as $year => $hours) {
            $entitlements = $this->entitlementRepository->findByEmployeeAndYear($request->getEmployee(), $year);
            $this->consumer->release($this->filterByBucket($entitlements, $bucket), $hours);
        }
    }

    /**
     * Restricts the entitlement list to those matching the AbsenceType's
     * required bucket, if any. null bucket = unified pool (all entitlements).
     *
     * @param list<LeaveEntitlement> $entitlements
     *
     * @return list<LeaveEntitlement>
     */
    private function filterByBucket(array $entitlements, ?LeaveEntitlementType $bucket): array
    {
        if (null === $bucket) {
            return $entitlements;
        }

        return array_values(array_filter(
            $entitlements,
            static fn (LeaveEntitlement $e): bool => $e->getType() === $bucket,
        ));
    }

    /**
     * @return array<int, float>
     */
    private function hoursByYear(LeaveRequest $request): array
    {
        $hoursByYear = [];
        foreach ($request->getDays() as $day) {
            if (LeaveDayStatus::Excluded === $day->getStatus()) {
                continue;
            }
            $year = (int) $day->getDate()->format('Y');
            $hoursByYear[$year] = ($hoursByYear[$year] ?? 0.0) + $day->getHours();
        }

        return $hoursByYear;
    }
}
