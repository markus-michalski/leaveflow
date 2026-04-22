<?php

declare(strict_types=1);

namespace App\Application\Approval;

use App\Application\Entitlement\EntitlementConsumer;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveDayStatus;
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
final readonly class LeaveRequestEntitlementBooker
{
    public function __construct(
        private LeaveEntitlementRepository $entitlementRepository,
        private EntitlementConsumer $consumer,
        private ClockInterface $clock,
    ) {
    }

    public function consume(LeaveRequest $request): void
    {
        if (!$request->getAbsenceType()->deductsFromLeave()) {
            return;
        }

        $asOf = $this->clock->now();
        foreach ($this->hoursByYear($request) as $year => $hours) {
            $entitlements = $this->entitlementRepository->findByEmployeeAndYear($request->getEmployee(), $year);
            $this->consumer->consume($entitlements, $hours, $asOf);
        }
    }

    public function release(LeaveRequest $request): void
    {
        if (!$request->getAbsenceType()->deductsFromLeave()) {
            return;
        }

        foreach ($this->hoursByYear($request) as $year => $hours) {
            $entitlements = $this->entitlementRepository->findByEmployeeAndYear($request->getEmployee(), $year);
            $this->consumer->release($entitlements, $hours);
        }
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
