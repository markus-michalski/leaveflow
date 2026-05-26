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

namespace App\Tests\Unit\Application\Ical;

use App\Application\Ical\IcalFeedBuilder;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IcalFeedBuilder::class)]
final class IcalFeedBuilderTest extends TestCase
{
    private IcalFeedBuilder $builder;
    private Company $company;
    private Location $location;
    private Employee $employee;
    private AbsenceType $vacation;

    protected function setUp(): void
    {
        $this->builder = new IcalFeedBuilder();
        $this->company = new Company('Acme GmbH');
        $this->location = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->employee = new Employee(
            $this->company,
            'Alice Example',
            'EMP-1',
            $this->location,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2025-01-01'),
        );
        $this->vacation = new AbsenceType(
            $this->company,
            'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
    }

    #[Test]
    public function emptyFeedRendersValidEmptyCalendar(): void
    {
        $output = $this->builder->buildPersonalFeed([]);

        self::assertStringContainsString('BEGIN:VCALENDAR', $output);
        self::assertStringContainsString('END:VCALENDAR', $output);
        self::assertStringContainsString('PRODID:-//LeaveFlow//Calendar Feed//DE', $output);
        self::assertStringNotContainsString('BEGIN:VEVENT', $output);
    }

    #[Test]
    public function personalFeedOmitsEmployeeNameFromSummary(): void
    {
        $request = $this->makeApprovedRequest('2026-07-06', '2026-07-10', 40.0);

        $output = $this->builder->buildPersonalFeed([$request]);

        self::assertStringContainsString('BEGIN:VEVENT', $output);
        self::assertStringContainsString('SUMMARY:Urlaub', $output);
        self::assertStringNotContainsString('Alice Example', $output);
    }

    #[Test]
    public function teamFeedPrefixesEmployeeName(): void
    {
        $request = $this->makeApprovedRequest('2026-07-06', '2026-07-10', 40.0);

        $output = $this->builder->buildTeamFeed([$request]);

        self::assertStringContainsString('SUMMARY:Alice Example – Urlaub', $output);
    }

    #[Test]
    public function multiDayRangeRendersStartAndEndDates(): void
    {
        $request = $this->makeApprovedRequest('2026-07-06', '2026-07-10', 40.0);

        $output = $this->builder->buildPersonalFeed([$request]);

        // All-day events: DTSTART date-only, DTEND date-only.
        self::assertStringContainsString('DTSTART;VALUE=DATE:20260706', $output);
        // eluceo serializes MultiDay as a TimeSpan, end-exclusive per RFC.
        // Range 06.07.-10.07. (inclusive) → DTEND = 11.07. (exclusive).
        self::assertStringContainsString('DTEND;VALUE=DATE:20260711', $output);
    }

    #[Test]
    public function singleDayRangeRendersOneDay(): void
    {
        $request = $this->makeApprovedRequest('2026-04-22', '2026-04-22', 8.0);

        $output = $this->builder->buildPersonalFeed([$request]);

        // SingleDay occurrence renders DTSTART only — RFC 5545 treats a
        // value-date DTSTART without DTEND as a single-day all-day event.
        self::assertStringContainsString('DTSTART;VALUE=DATE:20260422', $output);
        self::assertStringNotContainsString('DTEND', $output);
    }

    #[Test]
    public function descriptionContainsTotalHours(): void
    {
        $request = $this->makeApprovedRequest('2026-07-06', '2026-07-10', 40.0);

        $output = $this->builder->buildPersonalFeed([$request]);

        self::assertStringContainsString('DESCRIPTION:40\\,0 h', $output);
    }

    #[Test]
    public function multipleRequestsAllRendered(): void
    {
        $r1 = $this->makeApprovedRequest('2026-07-06', '2026-07-10', 40.0);
        $r2 = $this->makeApprovedRequest('2026-08-10', '2026-08-12', 24.0);

        $output = $this->builder->buildPersonalFeed([$r1, $r2]);

        self::assertSame(2, substr_count($output, 'BEGIN:VEVENT'));
        self::assertStringContainsString('20260706', $output);
        self::assertStringContainsString('20260810', $output);
    }

    private function makeApprovedRequest(string $start, string $end, float $totalHours): LeaveRequest
    {
        $startDt = new \DateTimeImmutable($start);
        $endDt = new \DateTimeImmutable($end);
        $request = new LeaveRequest(
            $this->employee,
            $this->vacation,
            $startDt,
            $endDt,
            LeaveDayType::FullDay,
            $startDt->modify('-1 day')->setTime(9, 0),
        );

        // Build a synthetic working-day breakdown that totals to $totalHours.
        $days = [];
        $cursor = $startDt;
        $workingDayCount = 0;
        while ($cursor <= $endDt) {
            if (\App\Domain\Enum\Weekday::fromDateTime($cursor)->value <= 5) {
                ++$workingDayCount;
            }
            $cursor = $cursor->modify('+1 day');
        }
        $hoursPerDay = $workingDayCount > 0 ? $totalHours / $workingDayCount : 0.0;
        $cursor = $startDt;
        while ($cursor <= $endDt) {
            $isWeekday = \App\Domain\Enum\Weekday::fromDateTime($cursor)->value <= 5;
            if ($isWeekday && $hoursPerDay > 0.0) {
                $days[] = new LeaveDay($cursor, $hoursPerDay, LeaveDayStatus::Working);
            } else {
                $days[] = new LeaveDay(
                    $cursor,
                    0.0,
                    LeaveDayStatus::Excluded,
                    \App\Domain\Enum\ExclusionReason::Weekend,
                );
            }
            $cursor = $cursor->modify('+1 day');
        }
        $request->applyBreakdown(new LeaveBreakdown($days));
        $request->setStatus(LeaveRequestStatus::Approved);

        return $request;
    }
}
