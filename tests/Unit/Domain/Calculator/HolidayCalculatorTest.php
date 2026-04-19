<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Calculator;

use App\Domain\Calculator\HolidayCalculator;
use App\Domain\Enum\FederalState;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\HolidayScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HolidayCalculator::class)]
#[CoversClass(Holiday::class)]
#[CoversClass(HolidayScope::class)]
#[CoversClass(FederalState::class)]
final class HolidayCalculatorTest extends TestCase
{
    private HolidayCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HolidayCalculator();
    }

    // --- Group 1: Easter Sunday (Gauss) ---------------------------------

    #[Test]
    #[DataProvider('easterSundayProvider')]
    public function calculatesEasterSundayCorrectly(int $year, string $expected): void
    {
        self::assertSame($expected, $this->calculator->easterSunday($year)->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function easterSundayProvider(): iterable
    {
        yield 'early 2008' => [2008, '2008-03-23'];
        yield '2017' => [2017, '2017-04-16'];
        yield '2018' => [2018, '2018-04-01'];
        yield '2024' => [2024, '2024-03-31'];
        yield '2025' => [2025, '2025-04-20'];
        yield '2026' => [2026, '2026-04-05'];
        yield '2027' => [2027, '2027-03-28'];
        yield '2030' => [2030, '2030-04-21'];
        yield 'late 2038' => [2038, '2038-04-25'];
    }

    #[Test]
    public function rejectsYearBefore1970(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->easterSunday(1969);
    }

    #[Test]
    public function rejectsYearAfter2200(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->easterSunday(2201);
    }

    // --- Group 2: Easter-derived national holidays ----------------------

    #[Test]
    #[DataProvider('easterDerivedProvider')]
    public function calculatesEasterDerivedHolidays(int $year, string $karfreitag, string $ostermontag, string $christiHimmelfahrt, string $pfingstmontag, string $fronleichnam): void
    {
        $bw = $this->extractDates($this->calculator->calculate($year, FederalState::BadenWuerttemberg));
        self::assertContains($karfreitag, $bw);
        self::assertContains($ostermontag, $bw);
        self::assertContains($christiHimmelfahrt, $bw);
        self::assertContains($pfingstmontag, $bw);
        self::assertContains($fronleichnam, $bw);
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    public static function easterDerivedProvider(): iterable
    {
        yield '2024' => [2024, '2024-03-29', '2024-04-01', '2024-05-09', '2024-05-20', '2024-05-30'];
        yield '2025' => [2025, '2025-04-18', '2025-04-21', '2025-05-29', '2025-06-09', '2025-06-19'];
        yield '2026' => [2026, '2026-04-03', '2026-04-06', '2026-05-14', '2026-05-25', '2026-06-04'];
        yield '2027' => [2027, '2027-03-26', '2027-03-29', '2027-05-06', '2027-05-17', '2027-05-27'];
    }

    // --- Group 3: Fixed national holidays are in every state ------------

    #[Test]
    #[DataProvider('federalStateProvider')]
    public function allStatesHaveFixedNationalHolidays(FederalState $state): void
    {
        $dates = $this->extractDates($this->calculator->calculate(2025, $state));
        self::assertContains('2025-01-01', $dates, 'Neujahr missing');
        self::assertContains('2025-05-01', $dates, 'Tag der Arbeit missing');
        self::assertContains('2025-10-03', $dates, 'Tag der Deutschen Einheit missing');
        self::assertContains('2025-12-25', $dates, '1. Weihnachtstag missing');
        self::assertContains('2025-12-26', $dates, '2. Weihnachtstag missing');
    }

    #[Test]
    #[DataProvider('federalStateProvider')]
    public function allStatesHaveEasterBasedNationalHolidays(FederalState $state): void
    {
        $dates = $this->extractDates($this->calculator->calculate(2025, $state));
        self::assertContains('2025-04-18', $dates, 'Karfreitag missing');
        self::assertContains('2025-04-21', $dates, 'Ostermontag missing');
        self::assertContains('2025-05-29', $dates, 'Christi Himmelfahrt missing');
        self::assertContains('2025-06-09', $dates, 'Pfingstmontag missing');
    }

    /**
     * @return iterable<string, array{0: FederalState}>
     */
    public static function federalStateProvider(): iterable
    {
        foreach (FederalState::cases() as $state) {
            yield $state->value => [$state];
        }
    }

    // --- Group 4: Heilige Drei Koenige — BW, BY, ST ---------------------

    #[Test]
    #[DataProvider('heiligeDreiKoenigeProvider')]
    public function heiligeDreiKoenigeApplies(FederalState $state, bool $expected): void
    {
        $dates = $this->extractDates($this->calculator->calculate(2025, $state));
        self::assertSame($expected, \in_array('2025-01-06', $dates, true));
    }

    /**
     * @return iterable<string, array{0: FederalState, 1: bool}>
     */
    public static function heiligeDreiKoenigeProvider(): iterable
    {
        $hasIt = [FederalState::BadenWuerttemberg, FederalState::Bayern, FederalState::SachsenAnhalt];
        foreach (FederalState::cases() as $state) {
            yield $state->value => [$state, \in_array($state, $hasIt, true)];
        }
    }

    // --- Group 5: Fronleichnam — BW, BY, HE, NW, RP, SL -----------------

    #[Test]
    #[DataProvider('fronleichnamProvider')]
    public function fronleichnamApplies(FederalState $state, bool $expected): void
    {
        $dates = $this->extractDates($this->calculator->calculate(2025, $state));
        self::assertSame($expected, \in_array('2025-06-19', $dates, true));
    }

    /**
     * @return iterable<string, array{0: FederalState, 1: bool}>
     */
    public static function fronleichnamProvider(): iterable
    {
        $hasIt = [
            FederalState::BadenWuerttemberg,
            FederalState::Bayern,
            FederalState::Hessen,
            FederalState::NordrheinWestfalen,
            FederalState::RheinlandPfalz,
            FederalState::Saarland,
        ];
        foreach (FederalState::cases() as $state) {
            yield $state->value => [$state, \in_array($state, $hasIt, true)];
        }
    }

    // --- Group 6: Mariae Himmelfahrt — BY (Catholic majority), SL -------

    #[Test]
    #[DataProvider('mariaeHimmelfahrtProvider')]
    public function mariaeHimmelfahrtApplies(FederalState $state, bool $expected): void
    {
        $dates = $this->extractDates($this->calculator->calculate(2025, $state));
        self::assertSame($expected, \in_array('2025-08-15', $dates, true));
    }

    /**
     * @return iterable<string, array{0: FederalState, 1: bool}>
     */
    public static function mariaeHimmelfahrtProvider(): iterable
    {
        $hasIt = [FederalState::Bayern, FederalState::Saarland];
        foreach (FederalState::cases() as $state) {
            yield $state->value => [$state, \in_array($state, $hasIt, true)];
        }
    }

    // --- Group 7: Allerheiligen — BW, BY, NW, RP, SL --------------------

    #[Test]
    #[DataProvider('allerheiligenProvider')]
    public function allerheiligenApplies(FederalState $state, bool $expected): void
    {
        $dates = $this->extractDates($this->calculator->calculate(2025, $state));
        self::assertSame($expected, \in_array('2025-11-01', $dates, true));
    }

    /**
     * @return iterable<string, array{0: FederalState, 1: bool}>
     */
    public static function allerheiligenProvider(): iterable
    {
        $hasIt = [
            FederalState::BadenWuerttemberg,
            FederalState::Bayern,
            FederalState::NordrheinWestfalen,
            FederalState::RheinlandPfalz,
            FederalState::Saarland,
        ];
        foreach (FederalState::cases() as $state) {
            yield $state->value => [$state, \in_array($state, $hasIt, true)];
        }
    }

    // --- Group 8: Buss- und Bettag — SN, Wed before 23.11. --------------

    #[Test]
    #[DataProvider('bussUndBettagProvider')]
    public function bussUndBettagApplies(int $year, string $expectedDate): void
    {
        $dates = $this->extractDates($this->calculator->calculate($year, FederalState::Sachsen));
        self::assertContains($expectedDate, $dates);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function bussUndBettagProvider(): iterable
    {
        yield '2023' => [2023, '2023-11-22'];
        yield '2024' => [2024, '2024-11-20'];
        yield '2025' => [2025, '2025-11-19'];
        yield '2026' => [2026, '2026-11-18'];
        yield '2027' => [2027, '2027-11-17'];
        yield '2028' => [2028, '2028-11-22'];
    }

    #[Test]
    public function bussUndBettagAppliesOnlyToSaxony(): void
    {
        foreach (FederalState::cases() as $state) {
            if (FederalState::Sachsen === $state) {
                continue;
            }
            $dates = $this->extractDates($this->calculator->calculate(2025, $state));
            self::assertNotContains('2025-11-19', $dates, "Buss- und Bettag should not be in {$state->value}");
        }
    }

    // --- Group 9: Reformationstag — stable states + year-dependent ------

    #[Test]
    #[DataProvider('reformationstagStableProvider')]
    public function reformationstagStableStatesAlwaysHaveIt(FederalState $state): void
    {
        foreach ([2016, 2018, 2020, 2025] as $year) {
            $dates = $this->extractDates($this->calculator->calculate($year, $state));
            self::assertContains("{$year}-10-31", $dates, "Reformationstag missing in {$state->value} {$year}");
        }
    }

    /**
     * @return iterable<string, array{0: FederalState}>
     */
    public static function reformationstagStableProvider(): iterable
    {
        $stable = [
            FederalState::Brandenburg,
            FederalState::MecklenburgVorpommern,
            FederalState::Sachsen,
            FederalState::SachsenAnhalt,
            FederalState::Thueringen,
        ];
        foreach ($stable as $state) {
            yield $state->value => [$state];
        }
    }

    #[Test]
    #[DataProvider('reformationstagLateAdoptersProvider')]
    public function reformationstagAddedInNorthernStatesFrom2018(FederalState $state): void
    {
        $dates2016 = $this->extractDates($this->calculator->calculate(2016, $state));
        self::assertNotContains('2016-10-31', $dates2016, "{$state->value} should not have Reformationstag in 2016");

        $dates2018 = $this->extractDates($this->calculator->calculate(2018, $state));
        self::assertContains('2018-10-31', $dates2018, "{$state->value} should have Reformationstag in 2018");

        $dates2025 = $this->extractDates($this->calculator->calculate(2025, $state));
        self::assertContains('2025-10-31', $dates2025, "{$state->value} should have Reformationstag in 2025");
    }

    /**
     * @return iterable<string, array{0: FederalState}>
     */
    public static function reformationstagLateAdoptersProvider(): iterable
    {
        $late = [
            FederalState::Bremen,
            FederalState::Hamburg,
            FederalState::Niedersachsen,
            FederalState::SchleswigHolstein,
        ];
        foreach ($late as $state) {
            yield $state->value => [$state];
        }
    }

    #[Test]
    #[DataProvider('federalStateProvider')]
    public function reformationstag2017WasNationwide(FederalState $state): void
    {
        $dates = $this->extractDates($this->calculator->calculate(2017, $state));
        self::assertContains('2017-10-31', $dates, "Reformationstag 2017 missing in {$state->value}");
    }

    #[Test]
    public function reformationstagNotInStatesWithoutIt(): void
    {
        $without = [
            FederalState::BadenWuerttemberg,
            FederalState::Bayern,
            FederalState::Berlin,
            FederalState::Hessen,
            FederalState::NordrheinWestfalen,
            FederalState::RheinlandPfalz,
            FederalState::Saarland,
        ];
        foreach ($without as $state) {
            $dates = $this->extractDates($this->calculator->calculate(2025, $state));
            self::assertNotContains('2025-10-31', $dates, "Reformationstag should not be in {$state->value} 2025");
        }
    }

    // --- Group 10: Frauentag (BE >= 2019, MV >= 2023) -------------------

    #[Test]
    public function berlinFrauentagAddedFrom2019(): void
    {
        $dates2018 = $this->extractDates($this->calculator->calculate(2018, FederalState::Berlin));
        self::assertNotContains('2018-03-08', $dates2018);

        $dates2019 = $this->extractDates($this->calculator->calculate(2019, FederalState::Berlin));
        self::assertContains('2019-03-08', $dates2019);

        $dates2025 = $this->extractDates($this->calculator->calculate(2025, FederalState::Berlin));
        self::assertContains('2025-03-08', $dates2025);
    }

    #[Test]
    public function mecklenburgVorpommernFrauentagAddedFrom2023(): void
    {
        $dates2022 = $this->extractDates($this->calculator->calculate(2022, FederalState::MecklenburgVorpommern));
        self::assertNotContains('2022-03-08', $dates2022);

        $dates2023 = $this->extractDates($this->calculator->calculate(2023, FederalState::MecklenburgVorpommern));
        self::assertContains('2023-03-08', $dates2023);
    }

    #[Test]
    public function frauentagNotInOtherStates(): void
    {
        foreach (FederalState::cases() as $state) {
            if (FederalState::Berlin === $state || FederalState::MecklenburgVorpommern === $state) {
                continue;
            }
            $dates = $this->extractDates($this->calculator->calculate(2025, $state));
            self::assertNotContains('2025-03-08', $dates, "Frauentag should not be in {$state->value}");
        }
    }

    // --- Group 11: Weltkindertag TH >= 2019 -----------------------------

    #[Test]
    public function thueringenWeltkindertagAddedFrom2019(): void
    {
        $dates2018 = $this->extractDates($this->calculator->calculate(2018, FederalState::Thueringen));
        self::assertNotContains('2018-09-20', $dates2018);

        $dates2019 = $this->extractDates($this->calculator->calculate(2019, FederalState::Thueringen));
        self::assertContains('2019-09-20', $dates2019);
    }

    #[Test]
    public function weltkindertagNotInOtherStates(): void
    {
        foreach (FederalState::cases() as $state) {
            if (FederalState::Thueringen === $state) {
                continue;
            }
            $dates = $this->extractDates($this->calculator->calculate(2025, $state));
            self::assertNotContains('2025-09-20', $dates, "Weltkindertag should not be in {$state->value}");
        }
    }

    // --- Group 12: Total holiday counts (spot-check per state, 2025) ----

    #[Test]
    #[DataProvider('expectedHolidayCount2025Provider')]
    public function holidayCountMatchesExpected2025(FederalState $state, int $expected): void
    {
        $count = \count($this->calculator->calculate(2025, $state));
        self::assertSame($expected, $count, "Holiday count for {$state->value} 2025");
    }

    /**
     * @return iterable<string, array{0: FederalState, 1: int}>
     */
    public static function expectedHolidayCount2025Provider(): iterable
    {
        // National base = 9 (Neujahr, Karfreitag, Ostermontag, 1.Mai, Himmelfahrt, Pfingstmontag, TdDE, 1.+2. Weihnachtstag)
        yield 'DE-BW' => [FederalState::BadenWuerttemberg, 12]; // +H3K, +Fronleichnam, +Allerheiligen
        yield 'DE-BY' => [FederalState::Bayern, 13]; // +H3K, +Fronleichnam, +MariaeHimmelfahrt, +Allerheiligen
        yield 'DE-BE' => [FederalState::Berlin, 10]; // +Frauentag
        yield 'DE-BB' => [FederalState::Brandenburg, 10]; // +Reformationstag
        yield 'DE-HB' => [FederalState::Bremen, 10]; // +Reformationstag
        yield 'DE-HH' => [FederalState::Hamburg, 10]; // +Reformationstag
        yield 'DE-HE' => [FederalState::Hessen, 10]; // +Fronleichnam
        yield 'DE-MV' => [FederalState::MecklenburgVorpommern, 11]; // +Frauentag, +Reformationstag
        yield 'DE-NI' => [FederalState::Niedersachsen, 10]; // +Reformationstag
        yield 'DE-NW' => [FederalState::NordrheinWestfalen, 11]; // +Fronleichnam, +Allerheiligen
        yield 'DE-RP' => [FederalState::RheinlandPfalz, 11]; // +Fronleichnam, +Allerheiligen
        yield 'DE-SL' => [FederalState::Saarland, 12]; // +Fronleichnam, +MariaeHimmelfahrt, +Allerheiligen
        yield 'DE-SN' => [FederalState::Sachsen, 11]; // +Reformationstag, +BussUndBettag
        yield 'DE-ST' => [FederalState::SachsenAnhalt, 11]; // +H3K, +Reformationstag
        yield 'DE-SH' => [FederalState::SchleswigHolstein, 10]; // +Reformationstag
        yield 'DE-TH' => [FederalState::Thueringen, 11]; // +Weltkindertag, +Reformationstag
    }

    // --- Group 13: Output invariants ------------------------------------

    #[Test]
    public function holidaysAreSortedAscendingByDate(): void
    {
        $holidays = $this->calculator->calculate(2025, FederalState::Bayern);
        $previous = null;
        foreach ($holidays as $holiday) {
            if (null !== $previous) {
                self::assertGreaterThan(
                    $previous->format('Y-m-d'),
                    $holiday->date->format('Y-m-d'),
                    'Holidays are not sorted ascending'
                );
            }
            $previous = $holiday->date;
        }
    }

    #[Test]
    public function returnedDatesUseCorrectYear(): void
    {
        $holidays = $this->calculator->calculate(2027, FederalState::NordrheinWestfalen);
        foreach ($holidays as $holiday) {
            self::assertSame('2027', $holiday->date->format('Y'));
        }
    }

    #[Test]
    public function nationalScopeForNationwideHolidays(): void
    {
        $holidays = $this->calculator->calculate(2025, FederalState::Bayern);
        $neujahr = $this->findByDate($holidays, '2025-01-01');
        self::assertSame(HolidayScope::National, $neujahr->scope);
    }

    #[Test]
    public function regionalScopeForStateHolidays(): void
    {
        $holidays = $this->calculator->calculate(2025, FederalState::Bayern);
        $h3k = $this->findByDate($holidays, '2025-01-06');
        self::assertSame(HolidayScope::Regional, $h3k->scope);
    }

    #[Test]
    public function translationKeysAreStableAndLowercaseDotted(): void
    {
        $holidays = $this->calculator->calculate(2025, FederalState::Bayern);
        foreach ($holidays as $holiday) {
            self::assertMatchesRegularExpression('/^holiday\.[a-z_]+$/', $holiday->nameKey);
        }
    }

    // --- Helpers --------------------------------------------------------

    /**
     * @param list<Holiday> $holidays
     *
     * @return list<string>
     */
    private function extractDates(array $holidays): array
    {
        return array_map(static fn (Holiday $h): string => $h->date->format('Y-m-d'), $holidays);
    }

    /**
     * @param list<Holiday> $holidays
     */
    private function findByDate(array $holidays, string $isoDate): Holiday
    {
        foreach ($holidays as $h) {
            if ($h->date->format('Y-m-d') === $isoDate) {
                return $h;
            }
        }
        self::fail("Holiday not found: {$isoDate}");
    }
}
