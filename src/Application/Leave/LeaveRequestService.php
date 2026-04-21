<?php

declare(strict_types=1);

namespace App\Application\Leave;

use App\Application\Holiday\HolidayService;
use App\Domain\Calculator\LeaveCalculator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\LeaveDayType;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\LeaveBreakdown;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Application-layer orchestrator for the LeaveRequest write path.
 *
 * Wraps the pure LeaveCalculator with the two stateful lookups it needs
 * (holidays resolved via HolidayService for the employee's work-location
 * federal state, the wall clock for requestedAt) and takes care of persisting
 * the resulting aggregate.
 *
 * Two public entry points:
 * - preview(): returns a LeaveBreakdown without creating anything (Turbo-Frame
 *   live preview on the request form)
 * - create(): creates the pending LeaveRequest with its per-day snapshot and
 *   flushes it
 */
final readonly class LeaveRequestService
{
    public function __construct(
        private HolidayService $holidayService,
        private LeaveCalculator $calculator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function preview(
        Employee $employee,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        LeaveDayType $dayType,
    ): LeaveBreakdown {
        $holidays = $this->resolveHolidays($employee, $startDate, $endDate);

        return $this->calculator->calculate($employee, $startDate, $endDate, $dayType, $holidays);
    }

    public function create(
        Employee $employee,
        AbsenceType $absenceType,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        LeaveDayType $dayType,
    ): LeaveRequest {
        $breakdown = $this->preview($employee, $startDate, $endDate, $dayType);

        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $absenceType,
            startDate: $startDate,
            endDate: $endDate,
            dayType: $dayType,
            requestedAt: $this->clock->now(),
        );
        $request->applyBreakdown($breakdown);

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return $request;
    }

    /**
     * @return list<Holiday>
     */
    private function resolveHolidays(
        Employee $employee,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        $state = FederalState::from($employee->getLocation()->getFederalState());
        $company = $employee->getCompany();

        $holidays = [];
        $startYear = (int) $startDate->format('Y');
        $endYear = (int) $endDate->format('Y');

        for ($year = $startYear; $year <= $endYear; ++$year) {
            foreach ($this->holidayService->getHolidaysForCompany($company, $state, $year) as $holiday) {
                $holidays[] = $holiday;
            }
        }

        return $holidays;
    }
}
