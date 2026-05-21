<?php

declare(strict_types=1);

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
}
