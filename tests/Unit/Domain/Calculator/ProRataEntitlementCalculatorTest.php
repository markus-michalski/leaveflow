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

namespace App\Tests\Unit\Domain\Calculator;

use App\Domain\Calculator\ProRataEntitlementCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProRataEntitlementCalculator::class)]
final class ProRataEntitlementCalculatorTest extends TestCase
{
    private ProRataEntitlementCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProRataEntitlementCalculator();
    }

    #[Test]
    #[DataProvider('entryCalculationProvider')]
    public function calculateForEntryReturnsProRataHours(
        string $joinedAt,
        int $year,
        float $annualHours,
        float $expected,
    ): void {
        $joined = new \DateTimeImmutable($joinedAt);
        $result = $this->calculator->calculateForEntry($joined, $year, $annualHours);

        self::assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * BUrlG §5 Zwölftelregel:
     *   - Joined on or before the 15th: join month counts as a full month
     *   - Joined on the 16th or later: join month does not count
     *   - effectiveMonths = months from first counted month through December
     *   - Result = annualHours * effectiveMonths / 12, rounded up to nearest 0.5h
     *
     * @return array<string, array{string, int, float, float}>
     */
    public static function entryCalculationProvider(): array
    {
        // 240h annual = 30 days * 8h (typical full entitlement)
        return [
            // Joined on or before 15th → month counts
            'Jan 1 — 12/12' => ['2024-01-01', 2024, 240.0, 240.0],  // 240 * 12/12
            'Jan 15 — 12/12' => ['2024-01-15', 2024, 240.0, 240.0],  // 240 * 12/12
            'Mar 1 — 10/12' => ['2024-03-01', 2024, 240.0, 200.0],  // 240 * 10/12
            'Mar 15 — 10/12' => ['2024-03-15', 2024, 240.0, 200.0],  // 240 * 10/12
            'Jul 1 — 6/12' => ['2024-07-01', 2024, 240.0, 120.0],  // 240 * 6/12
            'Jul 15 — 6/12' => ['2024-07-15', 2024, 240.0, 120.0],  // 240 * 6/12
            'Dec 1 — 1/12' => ['2024-12-01', 2024, 240.0, 20.0],   // 240 * 1/12
            'Dec 15 — 1/12' => ['2024-12-15', 2024, 240.0, 20.0],   // 240 * 1/12

            // Joined after the 15th → month does not count
            'Jan 16 — 11/12' => ['2024-01-16', 2024, 240.0, 220.0],  // 240 * 11/12
            'Jul 16 — 5/12' => ['2024-07-16', 2024, 240.0, 100.0],  // 240 * 5/12
            'Dec 16 — 0/12' => ['2024-12-16', 2024, 240.0, 0.0],
            'Dec 31 — 0/12' => ['2024-12-31', 2024, 240.0, 0.0],

            // Rounding: round up to nearest 0.5h
            // Jul 16 → Aug–Dec = 5 months, 200h * 5/12 = 83.33h → 83.5h
            'Jul 16 — 5/12 rounded' => ['2024-07-16', 2024, 200.0, 83.5],
            // Jun 1 → Jun–Dec = 7 months, 200h * 7/12 = 116.67h → 117.0h
            'Jun 1 — 7/12 rounded' => ['2024-06-01', 2024, 200.0, 117.0],

            // Joined in a previous year → full entitlement (no pro-rata)
            'Joined 2022 → 2024 full' => ['2022-06-15', 2024, 240.0, 240.0],
        ];
    }

    #[Test]
    public function calculateForEntryAnnualHoursZeroReturnsZero(): void
    {
        $joined = new \DateTimeImmutable('2024-06-01');
        $result = $this->calculator->calculateForEntry($joined, 2024, 0.0);

        self::assertSame(0.0, $result);
    }

    #[Test]
    public function calculateForEntryJoinedInDifferentYearReturnsFullAnnualHours(): void
    {
        $joined = new \DateTimeImmutable('2022-06-15');
        $result = $this->calculator->calculateForEntry($joined, 2024, 200.0);

        self::assertEqualsWithDelta(200.0, $result, 0.01);
    }

    // ── isReducedEntitlement ───────────────────────────────────────────────
    // Convenience used by the admin UI to decide whether to show the hint.

    #[Test]
    #[DataProvider('isReducedProvider')]
    public function isReducedEntitlementReturnsExpected(
        string $joinedAt,
        int $year,
        bool $expected,
    ): void {
        $joined = new \DateTimeImmutable($joinedAt);
        self::assertSame($expected, $this->calculator->isReducedEntitlement($joined, $year));
    }

    /**
     * @return array<string, array{string, int, bool}>
     */
    public static function isReducedProvider(): array
    {
        return [
            'Jan 1 in year — full, not reduced' => ['2024-01-01', 2024, false],
            'Jan 15 in year — full, not reduced' => ['2024-01-15', 2024, false],
            'Jan 16 in year — 11 twelfths, reduced' => ['2024-01-16', 2024, true],
            'Jul 1 in year — 6 twelfths, reduced' => ['2024-07-01', 2024, true],
            'Dec 31 in year — 0 twelfths, reduced' => ['2024-12-31', 2024, true],
            'Joined prior year — not reduced' => ['2022-07-01', 2024, false],
        ];
    }

    #[Test]
    public function isReducedEntitlementWithSameYearExitDetectsReduction(): void
    {
        // Joined Jan 1 (full join side) but exits in June — still reduced overall.
        $joined = new \DateTimeImmutable('2024-01-01');
        $leftAt = new \DateTimeImmutable('2024-06-30');

        self::assertTrue($this->calculator->isReducedEntitlement($joined, 2024, $leftAt));
    }

    // ── effectiveMonthsForPeriod ───────────────────────────────────────────

    #[Test]
    #[DataProvider('effectiveMonthsProvider')]
    public function effectiveMonthsForPeriodReturnsExpected(
        string $joinedAt,
        ?string $leftAt,
        int $year,
        int $expectedMonths,
    ): void {
        $joined = new \DateTimeImmutable($joinedAt);
        $left = null !== $leftAt ? new \DateTimeImmutable($leftAt) : null;

        self::assertSame($expectedMonths, $this->calculator->effectiveMonthsForPeriod($joined, $left, $year));
    }

    // ── effectiveMonthsEarnedAsOf ─────────────────────────────────────────

    #[Test]
    #[DataProvider('effectiveMonthsEarnedAsOfProvider')]
    public function effectiveMonthsEarnedAsOfReturnsExpected(
        string $joinedAt,
        string $asOf,
        int $year,
        int $expectedMonths,
    ): void {
        $joined = new \DateTimeImmutable($joinedAt);
        $asOfDate = new \DateTimeImmutable($asOf);

        self::assertSame($expectedMonths, $this->calculator->effectiveMonthsEarnedAsOf($joined, $asOfDate, $year));
    }

    /**
     * asOf month always counts regardless of day (no exit-rule cutoff).
     *
     * @return array<string, array{string, string, int, int}>
     */
    public static function effectiveMonthsEarnedAsOfProvider(): array
    {
        return [
            // Day ≤ 15 must count — this is the C1 regression case.
            'joined Jan 1, asOf Mar 10 (day ≤ 15) → 3 months' => ['2025-01-01', '2025-03-10', 2025, 3],
            'joined Jan 1, asOf Mar 15 (day = 15) → 3 months' => ['2025-01-01', '2025-03-15', 2025, 3],
            // Day > 15 — same result as before.
            'joined Jan 1, asOf Mar 17 (day > 15) → 3 months' => ['2025-01-01', '2025-03-17', 2025, 3],
            // Entry rule still applies: joined after 15th means join month doesn't count.
            'joined Mar 16, asOf Mar 20 → 0 months (join month excluded, asOf month = Mar = 3, firstCounted = Apr = 4)' => ['2025-03-16', '2025-03-20', 2025, 0],
            'joined Mar 16, asOf Apr 1 → 1 month (Apr)' => ['2025-03-16', '2025-04-01', 2025, 1],
            // Prior-year join → full year up to asOf month.
            'prior-year join, asOf Jun 30 → 6 months (Jan–Jun)' => ['2024-01-01', '2025-06-30', 2025, 6],
            // asOf in different year.
            'asOf before year → 0' => ['2025-01-01', '2024-12-31', 2025, 0],
            'asOf after year → 12' => ['2025-01-01', '2026-01-15', 2025, 12],
        ];
    }

    /**
     * Covers: join-only, exit-only cap, combined join+exit, edge months, prior-year join.
     *
     * Exit rule: exit on/before the 15th → month does not count.
     *            Exit on the 16th or later → month counts.
     *
     * @return array<string, array{string, string|null, int, int}>
     */
    public static function effectiveMonthsProvider(): array
    {
        return [
            // Join only (no exit)
            'join Mar 1, no exit → 10 months (Mar–Dec)' => ['2024-03-01', null, 2024, 10],
            'join Jan 1, no exit → 12 months' => ['2024-01-01', null, 2024, 12],
            'join Jan 15, no exit → 12 months' => ['2024-01-15', null, 2024, 12],
            'join Jan 16, no exit → 11 months (Feb–Dec)' => ['2024-01-16', null, 2024, 11],
            'join Dec 15, no exit → 1 month (Dec)' => ['2024-12-15', null, 2024, 1],
            'join Dec 16, no exit → 0 months' => ['2024-12-16', null, 2024, 0],

            // Exit only cap (joined prior year, full first-counted = Jan)
            'prior-year join, exit Jun 30 (day 30 > 15) → 6 months (Jan–Jun)' => ['2023-01-01', '2024-06-30', 2024, 6],
            'prior-year join, exit Jun 15 (day 15 ≤ 15) → 5 months (Jan–May)' => ['2023-01-01', '2024-06-15', 2024, 5],
            'prior-year join, exit Jan 1 (day 1 ≤ 15) → 0 months (lastCounted=0)' => ['2023-01-01', '2024-01-01', 2024, 0],
            'prior-year join, exit Dec 31 (day 31 > 15) → 12 months' => ['2023-01-01', '2024-12-31', 2024, 12],

            // Combined join + exit in same year
            'join Mar 1, exit Jul 1 (day 1 ≤ 15) → 4 months (Mar–Jun)' => ['2024-03-01', '2024-07-01', 2024, 4],
            'join Mar 1, exit Jul 16 (day 16 > 15) → 5 months (Mar–Jul)' => ['2024-03-01', '2024-07-16', 2024, 5],
            'join Mar 16, exit Jul 1 (day 1 ≤ 15) → 3 months (Apr–Jun)' => ['2024-03-16', '2024-07-01', 2024, 3],
            'join Jan 1, exit Jun 30 (day 30 > 15) → 6 months' => ['2024-01-01', '2024-06-30', 2024, 6],

            // Exit in a different year — exit does not cap
            'join Mar 1 2024, exit Jan 2025 → 10 months (exit year mismatch)' => ['2024-03-01', '2025-01-15', 2024, 10],
        ];
    }
}
