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

use App\Domain\Calculator\IllnessRunCalculator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayType;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IllnessRunCalculator::class)]
final class IllnessRunCalculatorTest extends TestCase
{
    private Company $company;
    private Employee $employee;
    private AbsenceType $sick;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 36);
        $location = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->employee = new Employee(
            $this->company,
            'Erika Mustermann',
            'EMP-1',
            $location,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->sick = new AbsenceType($this->company, 'Krankheit', false, false, '#EF4444');
    }

    #[Test]
    public function returnsNullWhenNoRequestsGiven(): void
    {
        $calc = new IllnessRunCalculator();

        self::assertNull($calc->findActiveRun([], new \DateTimeImmutable('2026-05-09')));
    }

    #[Test]
    public function returnsNullWhenRunIsBelowThreshold(): void
    {
        // 41 days — one day short of 42-day threshold.
        $req = $this->makeRequest('2026-04-01', '2026-05-11'); // 41 days inclusive
        $calc = new IllnessRunCalculator();

        self::assertNull($calc->findActiveRun([$req], new \DateTimeImmutable('2026-05-11')));
    }

    #[Test]
    public function returnsRunWhenSingleRequestHitsThresholdExactly(): void
    {
        // 42 calendar days inclusive: 2026-04-01..2026-05-12.
        $req = $this->makeRequest('2026-04-01', '2026-05-12');
        $calc = new IllnessRunCalculator();

        $run = $calc->findActiveRun([$req], new \DateTimeImmutable('2026-05-12'));

        self::assertNotNull($run);
        self::assertSame('2026-04-01', $run->startedOn->format('Y-m-d'));
        self::assertSame('2026-05-12', $run->endsOn->format('Y-m-d'));
        self::assertSame(42, $run->dayCount);
    }

    #[Test]
    public function mergesAdjacentRequestsIntoSingleRun(): void
    {
        // Three back-to-back requests covering Mar 1..Apr 11 (42 days).
        $req1 = $this->makeRequest('2026-03-01', '2026-03-15'); // 15 days
        $req2 = $this->makeRequest('2026-03-16', '2026-03-31'); // 16 days
        $req3 = $this->makeRequest('2026-04-01', '2026-04-11'); // 11 days
        $calc = new IllnessRunCalculator();

        $run = $calc->findActiveRun([$req1, $req2, $req3], new \DateTimeImmutable('2026-04-11'));

        self::assertNotNull($run);
        self::assertSame('2026-03-01', $run->startedOn->format('Y-m-d'));
        self::assertSame(42, $run->dayCount);
    }

    #[Test]
    public function gapBreaksRunEvenWhenTotalSumWouldExceedThreshold(): void
    {
        // 30 days, then 1-day gap, then 30 more — sum=60, but no consecutive
        // run hits 42. Should return null.
        $req1 = $this->makeRequest('2026-03-01', '2026-03-30'); // 30 days
        $req2 = $this->makeRequest('2026-04-01', '2026-04-30'); // 30 days, gap on 03-31
        $calc = new IllnessRunCalculator();

        self::assertNull($calc->findActiveRun([$req1, $req2], new \DateTimeImmutable('2026-04-30')));
    }

    #[Test]
    public function ignoresOldRunsThatEndedBeforeAsOf(): void
    {
        // 42-day run ending way back in 2024 — not the current ongoing
        // illness. Today's check should ignore it.
        $oldRun = $this->makeRequest('2024-01-01', '2024-02-11'); // 42 days
        $newShort = $this->makeRequest('2026-05-01', '2026-05-08'); // 8 days
        $calc = new IllnessRunCalculator();

        self::assertNull($calc->findActiveRun([$oldRun, $newShort], new \DateTimeImmutable('2026-05-09')));
    }

    #[Test]
    public function returnsActiveRunEvenWhenTodayLiesPastEndDate(): void
    {
        // Run ended yesterday, ≥42 days. Sweep runs today after the
        // recovery — still alarm-worthy because the threshold was crossed.
        $req = $this->makeRequest('2026-03-01', '2026-04-11'); // 42 days
        $calc = new IllnessRunCalculator();

        $run = $calc->findActiveRun([$req], new \DateTimeImmutable('2026-04-12'));

        self::assertNotNull($run);
        self::assertSame(42, $run->dayCount);
    }

    #[Test]
    public function picksLatestRunWhenMultipleQualify(): void
    {
        // Two long runs, both ≥42 days, separated by gap. Only the most
        // recent counts as "active" — the earlier one would have already
        // been alerted on a prior sweep.
        $earlier = $this->makeRequest('2025-01-01', '2025-02-15'); // 46 days
        $later = $this->makeRequest('2026-03-01', '2026-04-15'); // 46 days
        $calc = new IllnessRunCalculator();

        $run = $calc->findActiveRun([$earlier, $later], new \DateTimeImmutable('2026-04-15'));

        self::assertNotNull($run);
        self::assertSame('2026-03-01', $run->startedOn->format('Y-m-d'));
    }

    #[Test]
    public function ignoresFutureRequestsBeyondAsOf(): void
    {
        // Future-dated request — admin scheduled a planned absence ahead.
        // Today's count must not include days that haven't happened yet.
        $req = $this->makeRequest('2026-06-01', '2026-07-12'); // 42 days, all future
        $calc = new IllnessRunCalculator();

        self::assertNull($calc->findActiveRun([$req], new \DateTimeImmutable('2026-05-09')));
    }

    #[Test]
    public function clampsRunInProgressToAsOfDate(): void
    {
        // Long ongoing illness — admin pre-recorded 60 days, today is day 42.
        // Run should report 42, not 60.
        $req = $this->makeRequest('2026-04-01', '2026-05-30'); // 60 days planned
        $calc = new IllnessRunCalculator();

        $run = $calc->findActiveRun([$req], new \DateTimeImmutable('2026-05-12')); // day 42

        self::assertNotNull($run);
        self::assertSame('2026-04-01', $run->startedOn->format('Y-m-d'));
        self::assertSame('2026-05-12', $run->endsOn->format('Y-m-d'));
        self::assertSame(42, $run->dayCount);
    }

    private function makeRequest(string $start, string $end): LeaveRequest
    {
        return new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->sick,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable($start),
        );
    }
}
