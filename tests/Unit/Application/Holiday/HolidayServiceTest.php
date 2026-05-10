<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Holiday;

use App\Application\Holiday\HolidayService;
use App\Domain\Calculator\HolidayCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use App\Domain\Entity\Employee;
use App\Domain\Entity\HolidayOverride;
use App\Domain\Entity\Location;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType;
use App\Domain\Repository\CompanyHolidayRepository;
use App\Domain\Repository\HolidayOverrideRepository;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\HolidayScope;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HolidayService::class)]
final class HolidayServiceTest extends TestCase
{
    private HolidayCalculator $calculator;
    private HolidayOverrideRepository&\PHPUnit\Framework\MockObject\Stub $overrideRepo;
    private CompanyHolidayRepository&\PHPUnit\Framework\MockObject\Stub $companyHolidayRepo;
    private HolidayService $service;
    private Company $company;

    protected function setUp(): void
    {
        $this->calculator = new HolidayCalculator();
        $this->overrideRepo = $this->createStub(HolidayOverrideRepository::class);
        $this->companyHolidayRepo = $this->createStub(CompanyHolidayRepository::class);
        $this->service = new HolidayService($this->calculator, $this->overrideRepo, $this->companyHolidayRepo);
        $this->company = new Company('Acme GmbH');
    }

    #[Test]
    public function returnsCalculatorOutputWhenNoOverridesExist(): void
    {
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([]);

        $holidays = $this->service->getHolidaysForCompany($this->company, FederalState::Bayern, 2025);

        self::assertCount(13, $holidays);
    }

    #[Test]
    public function removedOverrideSuppressesCalculatedHoliday(): void
    {
        $removeMariaeHimmelfahrt = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2025-08-15'),
            'Mariä Himmelfahrt (entfernt)',
            HolidayOverrideType::Removed,
        );
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([$removeMariaeHimmelfahrt]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([]);

        $holidays = $this->service->getHolidaysForCompany($this->company, FederalState::Bayern, 2025);
        $dates = array_map(static fn (Holiday $h): string => $h->date->format('Y-m-d'), $holidays);

        self::assertNotContains('2025-08-15', $dates);
        self::assertCount(12, $holidays);
    }

    #[Test]
    public function addedOverrideAppearsInResult(): void
    {
        $augsburg = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2025-08-08'),
            'Augsburger Friedensfest',
            HolidayOverrideType::Added,
        );
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([$augsburg]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([]);

        $holidays = $this->service->getHolidaysForCompany($this->company, FederalState::Bayern, 2025);
        $match = $this->findByDate($holidays, '2025-08-08');

        self::assertSame('Augsburger Friedensfest', $match->nameKey);
        self::assertSame(HolidayScope::Regional, $match->scope);
        self::assertCount(14, $holidays);
    }

    #[Test]
    public function companyHolidayAppearsWithCompanyScope(): void
    {
        $brueckentag = new CompanyHoliday($this->company, new \DateTimeImmutable('2025-05-30'), 'Brückentag');
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([$brueckentag]);

        $holidays = $this->service->getHolidaysForCompany($this->company, FederalState::Hessen, 2025);
        $match = $this->findByDate($holidays, '2025-05-30');

        self::assertSame('Brückentag', $match->nameKey);
        self::assertSame(HolidayScope::Company, $match->scope);
    }

    #[Test]
    public function resultIsSortedByDateAscending(): void
    {
        $augsburg = new HolidayOverride($this->company, FederalState::Bayern, new \DateTimeImmutable('2025-08-08'), 'Friedensfest', HolidayOverrideType::Added);
        $brueckentag = new CompanyHoliday($this->company, new \DateTimeImmutable('2025-05-30'), 'Brückentag');
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([$augsburg]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([$brueckentag]);

        $holidays = $this->service->getHolidaysForCompany($this->company, FederalState::Bayern, 2025);

        $prev = null;
        foreach ($holidays as $h) {
            if (null !== $prev) {
                self::assertGreaterThan($prev->format('Y-m-d'), $h->date->format('Y-m-d'));
            }
            $prev = $h->date;
        }
    }

    #[Test]
    public function removedOverrideOnlyMatchesExactDate(): void
    {
        // Override for 2025-08-14 should NOT remove Mariä Himmelfahrt on 2025-08-15.
        $wrongDate = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2025-08-14'),
            'Not a real holiday',
            HolidayOverrideType::Removed,
        );
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([$wrongDate]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([]);

        $holidays = $this->service->getHolidaysForCompany($this->company, FederalState::Bayern, 2025);
        $dates = array_map(static fn (Holiday $h): string => $h->date->format('Y-m-d'), $holidays);

        self::assertContains('2025-08-15', $dates);
    }

    #[Test]
    public function isHolidayReturnsTrueForCalculatedHoliday(): void
    {
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([]);

        self::assertTrue($this->service->isHoliday($this->company, FederalState::Bayern, new \DateTimeImmutable('2025-01-01')));
        self::assertFalse($this->service->isHoliday($this->company, FederalState::Bayern, new \DateTimeImmutable('2025-01-02')));
    }

    #[Test]
    public function getHolidaysForEmployeeMixesStateBaselineWithLocationOverride(): void
    {
        $augsburg = new Location($this->company, 'Augsburg', 'DE', FederalState::Bayern->value, 'Augsburg');
        $employee = new Employee(
            $this->company,
            'Anna Augsburg',
            'EMP-AUG',
            $augsburg,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );

        $augsburgFriedensfest = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2025-08-08'),
            'Augsburger Friedensfest',
            HolidayOverrideType::Added,
            $augsburg,
        );

        $this->overrideRepo->method('findByEmployeeAndYear')->willReturn([$augsburgFriedensfest]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([]);

        $holidays = $this->service->getHolidaysForEmployee($employee, 2025);
        $dates = array_map(static fn (Holiday $h): string => $h->date->format('Y-m-d'), $holidays);

        self::assertContains('2025-08-08', $dates);
        // Bayern baseline still in: Mariä Himmelfahrt the week after.
        self::assertContains('2025-08-15', $dates);
    }

    #[Test]
    public function getHolidaysForCompanyIgnoresLocationSpecificOverrides(): void
    {
        // The state-wide overview should reflect the default for the
        // state — location-specific overrides are filtered upstream by
        // the repository's findByCompanyYearAndState. Stub it as if the
        // repo had already filtered them out.
        $this->overrideRepo->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepo->method('findByCompanyAndYear')->willReturn([]);

        $holidays = $this->service->getHolidaysForCompany($this->company, FederalState::Bayern, 2025);
        $dates = array_map(static fn (Holiday $h): string => $h->date->format('Y-m-d'), $holidays);

        // Augsburger Friedensfest is NOT in the calculator baseline and
        // not added via state-wide override → must not appear.
        self::assertNotContains('2025-08-08', $dates);
    }

    /**
     * @param list<Holiday> $holidays
     */
    private function findByDate(array $holidays, string $iso): Holiday
    {
        foreach ($holidays as $h) {
            if ($h->date->format('Y-m-d') === $iso) {
                return $h;
            }
        }
        self::fail("Holiday not found: {$iso}");
    }
}
