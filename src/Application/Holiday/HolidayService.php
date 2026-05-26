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

namespace App\Application\Holiday;

use App\Domain\Calculator\HolidayCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use App\Domain\Entity\Employee;
use App\Domain\Entity\HolidayOverride;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType;
use App\Domain\Repository\CompanyHolidayRepository;
use App\Domain\Repository\HolidayOverrideRepository;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\HolidayScope;

/**
 * Resolves the effective holiday calendar for a company.
 *
 * Combines the calculator's federal-state baseline with company-specific
 * overrides (added/removed holidays tied to a state, optionally narrowed
 * to a specific Location since Phase 9) and company-wide non-working
 * days. Callers receive a single, merged, date-sorted list.
 *
 * Two entry points by intent:
 * - {@see getHolidaysForCompany} — admin/state overview, ignores
 *   location-specific overrides so the displayed calendar reflects the
 *   default for that state.
 * - {@see getHolidaysForEmployee} — runtime calculations (leave
 *   requests, illness sweeps), applies the employee's location-specific
 *   overrides too.
 */
final readonly class HolidayService
{
    public function __construct(
        private HolidayCalculator $calculator,
        private HolidayOverrideRepository $overrideRepository,
        private CompanyHolidayRepository $companyHolidayRepository,
    ) {
    }

    /**
     * State-wide view for the admin overview. Picks up only state-wide
     * overrides (location IS NULL); per-location overrides are visible
     * via the override management UI.
     *
     * @return list<Holiday>
     */
    public function getHolidaysForCompany(Company $company, FederalState $state, int $year): array
    {
        $overrides = $this->overrideRepository->findByCompanyYearAndState($company, $year, $state);

        return $this->merge(
            base: $this->calculator->calculate($year, $state),
            overrides: $overrides,
            companyHolidays: $this->companyHolidayRepository->findByCompanyAndYear($company, $year),
        );
    }

    /**
     * Employee-scoped view used by leave calculation. State derives from
     * the employee's work-location FederalState; overrides include both
     * state-wide entries and the location-specific ones for this exact
     * Location (the "Augsburger Friedensfest only in Augsburg" case).
     *
     * @return list<Holiday>
     */
    public function getHolidaysForEmployee(Employee $employee, int $year): array
    {
        $location = $employee->getLocation();
        $state = FederalState::from($location->getFederalState());

        $overrides = $this->overrideRepository->findByEmployeeAndYear($employee, $year);

        return $this->merge(
            base: $this->calculator->calculate($year, $state),
            overrides: $overrides,
            companyHolidays: $this->companyHolidayRepository->findByCompanyAndYear($employee->getCompany(), $year),
        );
    }

    public function isHoliday(Company $company, FederalState $state, \DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');
        foreach ($this->getHolidaysForCompany($company, $state, $year) as $holiday) {
            if ($holiday->isOn($date)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Holiday>          $base
     * @param list<HolidayOverride>  $overrides
     * @param list<CompanyHoliday>   $companyHolidays
     *
     * @return list<Holiday>
     */
    private function merge(array $base, array $overrides, array $companyHolidays): array
    {
        $removedDates = [];
        $added = [];
        foreach ($overrides as $override) {
            $iso = $override->getDate()->format('Y-m-d');
            if (HolidayOverrideType::Removed === $override->getType()) {
                $removedDates[$iso] = true;
                continue;
            }
            $added[] = new Holiday($override->getDate(), $override->getName(), HolidayScope::Regional);
        }

        $filtered = array_values(array_filter(
            $base,
            static fn (Holiday $h): bool => !isset($removedDates[$h->date->format('Y-m-d')])
        ));

        $companyEntries = array_map(
            static fn (CompanyHoliday $ch): Holiday => new Holiday($ch->getDate(), $ch->getName(), HolidayScope::Company),
            $companyHolidays
        );

        $merged = array_merge($filtered, $added, $companyEntries);
        usort($merged, static fn (Holiday $a, Holiday $b): int => $a->date <=> $b->date);

        return $merged;
    }
}
