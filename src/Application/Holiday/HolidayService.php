<?php

declare(strict_types=1);

namespace App\Application\Holiday;

use App\Domain\Calculator\HolidayCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
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
 * overrides (added/removed holidays tied to a state) and company-wide
 * non-working days. Callers receive a single, merged, date-sorted list.
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
     * @return list<Holiday>
     */
    public function getHolidaysForCompany(Company $company, FederalState $state, int $year): array
    {
        $base = $this->calculator->calculate($year, $state);
        $overrides = $this->overrideRepository->findByCompanyYearAndState($company, $year, $state);
        $companyHolidays = $this->companyHolidayRepository->findByCompanyAndYear($company, $year);

        $removedDates = [];
        $added = [];
        foreach ($overrides as $override) {
            $iso = $override->getDate()->format('Y-m-d');
            if (HolidayOverrideType::Removed === $override->getType()) {
                $removedDates[$iso] = true;
                continue;
            }
            $added[] = $this->toHolidayFromOverride($override);
        }

        $filtered = array_values(array_filter(
            $base,
            static fn (Holiday $h): bool => !isset($removedDates[$h->date->format('Y-m-d')])
        ));

        $companyEntries = array_map(
            fn (CompanyHoliday $ch): Holiday => $this->toHolidayFromCompanyHoliday($ch),
            $companyHolidays
        );

        $merged = array_merge($filtered, $added, $companyEntries);
        usort($merged, static fn (Holiday $a, Holiday $b): int => $a->date <=> $b->date);

        return $merged;
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

    private function toHolidayFromOverride(HolidayOverride $override): Holiday
    {
        return new Holiday($override->getDate(), $override->getName(), HolidayScope::Regional);
    }

    private function toHolidayFromCompanyHoliday(CompanyHoliday $holiday): Holiday
    {
        return new Holiday($holiday->getDate(), $holiday->getName(), HolidayScope::Company);
    }
}
