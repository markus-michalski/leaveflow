<?php

declare(strict_types=1);

namespace App\Domain\Calculator;

use App\Domain\Enum\FederalState;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\HolidayScope;

/**
 * Pure, stateless calculator for German public holidays.
 *
 * Input: year + federal state. Output: sorted list of Holiday VOs for the
 * given year, honoring state-specific rules and year-dependent adoption
 * (e.g. Frauentag in Berlin from 2019, Reformationstag in northern states
 * from 2018, 500-year Reformation jubilee 2017 nationwide).
 *
 * No DB access — Company-level overrides are applied by HolidayService on top.
 */
final class HolidayCalculator
{
    private const int MIN_YEAR = 1970;
    private const int MAX_YEAR = 2200;

    /**
     * @return list<Holiday>
     */
    public function calculate(int $year, FederalState $state): array
    {
        $this->assertSupportedYear($year);

        $easter = $this->easterSunday($year);
        $holidays = [];

        // Fixed national holidays.
        $holidays[] = $this->fixed($year, 1, 1, 'neujahr', HolidayScope::National);
        $holidays[] = $this->fixed($year, 5, 1, 'tag_der_arbeit', HolidayScope::National);
        $holidays[] = $this->fixed($year, 10, 3, 'tag_der_deutschen_einheit', HolidayScope::National);
        $holidays[] = $this->fixed($year, 12, 25, 'erster_weihnachtstag', HolidayScope::National);
        $holidays[] = $this->fixed($year, 12, 26, 'zweiter_weihnachtstag', HolidayScope::National);

        // Easter-derived national holidays.
        $holidays[] = new Holiday($easter->modify('-2 days'), 'holiday.karfreitag', HolidayScope::National);
        $holidays[] = new Holiday($easter->modify('+1 day'), 'holiday.ostermontag', HolidayScope::National);
        $holidays[] = new Holiday($easter->modify('+39 days'), 'holiday.christi_himmelfahrt', HolidayScope::National);
        $holidays[] = new Holiday($easter->modify('+50 days'), 'holiday.pfingstmontag', HolidayScope::National);

        // Heilige Drei Koenige — BW, BY, ST.
        if (\in_array($state, [FederalState::BadenWuerttemberg, FederalState::Bayern, FederalState::SachsenAnhalt], true)) {
            $holidays[] = $this->fixed($year, 1, 6, 'heilige_drei_koenige', HolidayScope::Regional);
        }

        // Internationaler Frauentag — Berlin from 2019, MV from 2023.
        if (FederalState::Berlin === $state && $year >= 2019) {
            $holidays[] = $this->fixed($year, 3, 8, 'frauentag', HolidayScope::Regional);
        }
        if (FederalState::MecklenburgVorpommern === $state && $year >= 2023) {
            $holidays[] = $this->fixed($year, 3, 8, 'frauentag', HolidayScope::Regional);
        }

        // Fronleichnam — BW, BY, HE, NW, RP, SL.
        if (\in_array($state, [
            FederalState::BadenWuerttemberg,
            FederalState::Bayern,
            FederalState::Hessen,
            FederalState::NordrheinWestfalen,
            FederalState::RheinlandPfalz,
            FederalState::Saarland,
        ], true)) {
            $holidays[] = new Holiday($easter->modify('+60 days'), 'holiday.fronleichnam', HolidayScope::Regional);
        }

        // Mariae Himmelfahrt — BY (Catholic majority), SL.
        if (\in_array($state, [FederalState::Bayern, FederalState::Saarland], true)) {
            $holidays[] = $this->fixed($year, 8, 15, 'mariae_himmelfahrt', HolidayScope::Regional);
        }

        // Weltkindertag — TH from 2019.
        if (FederalState::Thueringen === $state && $year >= 2019) {
            $holidays[] = $this->fixed($year, 9, 20, 'weltkindertag', HolidayScope::Regional);
        }

        // Reformationstag — BB, MV, SN, ST, TH always; HB, HH, NI, SH from 2018; 2017 nationwide.
        if ($this->hasReformationstag($state, $year)) {
            $holidays[] = $this->fixed($year, 10, 31, 'reformationstag', HolidayScope::Regional);
        }

        // Allerheiligen — BW, BY, NW, RP, SL.
        if (\in_array($state, [
            FederalState::BadenWuerttemberg,
            FederalState::Bayern,
            FederalState::NordrheinWestfalen,
            FederalState::RheinlandPfalz,
            FederalState::Saarland,
        ], true)) {
            $holidays[] = $this->fixed($year, 11, 1, 'allerheiligen', HolidayScope::Regional);
        }

        // Buss- und Bettag — SN only, Wed before 23.11.
        if (FederalState::Sachsen === $state) {
            $holidays[] = new Holiday($this->bussUndBettag($year), 'holiday.buss_und_bettag', HolidayScope::Regional);
        }

        usort($holidays, static fn (Holiday $a, Holiday $b): int => $a->date <=> $b->date);

        return $holidays;
    }

    /**
     * Easter Sunday by Gauss's algorithm (valid 1583–4099, we cap at 1970–2200).
     */
    public function easterSunday(int $year): \DateTimeImmutable
    {
        $this->assertSupportedYear($year);

        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return $this->date($year, $month, $day);
    }

    private function hasReformationstag(FederalState $state, int $year): bool
    {
        if (2017 === $year) {
            return true;
        }

        $alwaysStates = [
            FederalState::Brandenburg,
            FederalState::MecklenburgVorpommern,
            FederalState::Sachsen,
            FederalState::SachsenAnhalt,
            FederalState::Thueringen,
        ];
        if (\in_array($state, $alwaysStates, true)) {
            return true;
        }

        $since2018 = [
            FederalState::Bremen,
            FederalState::Hamburg,
            FederalState::Niedersachsen,
            FederalState::SchleswigHolstein,
        ];

        return $year >= 2018 && \in_array($state, $since2018, true);
    }

    private function bussUndBettag(int $year): \DateTimeImmutable
    {
        // Wednesday strictly before November 23. Range: Nov 16..22.
        $candidate = $this->date($year, 11, 22);
        while ('Wednesday' !== $candidate->format('l')) {
            $candidate = $candidate->modify('-1 day');
        }

        return $candidate;
    }

    private function fixed(int $year, int $month, int $day, string $key, HolidayScope $scope): Holiday
    {
        return new Holiday($this->date($year, $month, $day), 'holiday.'.$key, $scope);
    }

    private function date(int $year, int $month, int $day): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setDate($year, $month, $day)->setTime(0, 0);
    }

    private function assertSupportedYear(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new \InvalidArgumentException(\sprintf('Year %d is outside supported range [%d, %d].', $year, self::MIN_YEAR, self::MAX_YEAR));
        }
    }
}
