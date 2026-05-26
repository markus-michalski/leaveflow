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

namespace App\Tests\Unit\Application\Statistics;

use App\Application\Statistics\MonthlyDistributionFormatter;
use App\Application\Statistics\MonthlyDistributionStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonthlyDistributionFormatter::class)]
#[CoversClass(MonthlyDistributionStats::class)]
final class MonthlyDistributionFormatterTest extends TestCase
{
    private MonthlyDistributionFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MonthlyDistributionFormatter();
    }

    #[Test]
    public function emptyDistributionYieldsZeroStats(): void
    {
        $stats = $this->formatter->summarize(array_fill(0, 12, 0.0));

        self::assertSame(0.0, $stats->totalHours);
        self::assertNull($stats->peakMonthIndex);
        self::assertSame(0.0, $stats->peakMonthHours);
        self::assertSame(12, $stats->emptyMonthCount);
        self::assertSame([0.0, 0.0, 0.0, 0.0], $stats->quarterTotals);
    }

    #[Test]
    public function picksPeakMonthByHighestValue(): void
    {
        $distribution = [0.0, 0.0, 80.0, 0.0, 40.0, 0.0, 120.0, 0.0, 0.0, 0.0, 0.0, 0.0];

        $stats = $this->formatter->summarize($distribution);

        self::assertSame(6, $stats->peakMonthIndex); // 0-indexed: July
        self::assertSame(120.0, $stats->peakMonthHours);
    }

    #[Test]
    public function peakMonthTiesReturnFirstOccurrence(): void
    {
        $distribution = [0.0, 40.0, 0.0, 40.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];

        $stats = $this->formatter->summarize($distribution);

        self::assertSame(1, $stats->peakMonthIndex); // February
    }

    #[Test]
    public function quarterTotalsAggregateThreeMonthsEach(): void
    {
        // Jan=10, Feb=20, Mar=30, Apr=40, May=50, Jun=60, Jul=70, Aug=80, Sep=90, Oct=100, Nov=110, Dec=120
        $distribution = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 100.0, 110.0, 120.0];

        $stats = $this->formatter->summarize($distribution);

        self::assertSame(60.0, $stats->quarterTotals[0]);  // Q1: Jan+Feb+Mar
        self::assertSame(150.0, $stats->quarterTotals[1]); // Q2: Apr+May+Jun
        self::assertSame(240.0, $stats->quarterTotals[2]); // Q3: Jul+Aug+Sep
        self::assertSame(330.0, $stats->quarterTotals[3]); // Q4: Oct+Nov+Dec
        self::assertSame(780.0, $stats->totalHours);
    }

    #[Test]
    public function peakQuarterReturnsQuarterIndexOfHighestQuarterTotal(): void
    {
        $distribution = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 5.0, 5.0, 5.0];

        $stats = $this->formatter->summarize($distribution);

        self::assertSame(2, $stats->peakQuarterIndex); // Q3 has 240h
        self::assertSame(240.0, $stats->peakQuarterHours);
    }

    #[Test]
    public function emptyMonthCountReflectsZeroBuckets(): void
    {
        $distribution = [40.0, 0.0, 80.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];

        $stats = $this->formatter->summarize($distribution);

        self::assertSame(10, $stats->emptyMonthCount);
    }

    #[Test]
    public function rejectsDistributionWithWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->formatter->summarize([1.0, 2.0, 3.0]);
    }
}
