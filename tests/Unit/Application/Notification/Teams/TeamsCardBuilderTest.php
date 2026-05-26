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

namespace App\Tests\Unit\Application\Notification\Teams;

use App\Application\Notification\Teams\TeamsCardBuilder;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TeamsCardBuilder::class)]
class TeamsCardBuilderTest extends TestCase
{
    private TeamsCardBuilder $builder;
    private LeaveRequest $request;

    protected function setUp(): void
    {
        $this->builder = new TeamsCardBuilder();

        $company = new Company('Acme GmbH');
        $hq = new Location($company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $dept = new Department($company, 'Engineering');

        $user = new User($company, 'jane@acme.test', UserRole::Employee);
        $employee = new Employee(
            company: $company,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-001',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: $user,
        );
        $employee->assignToDepartment($dept);

        $absenceType = new AbsenceType(
            company: $company,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );

        $this->request = new LeaveRequest(
            employee: $employee,
            absenceType: $absenceType,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:30:00'),
        );
    }

    #[Test]
    public function buildPendingRequestCardReturnsTeamsEnvelope(): void
    {
        $card = $this->builder->buildPendingRequestCard($this->request);

        self::assertSame('message', $card['type']);
        self::assertArrayHasKey('attachments', $card);
        self::assertCount(1, $card['attachments']);
        self::assertSame('application/vnd.microsoft.card.adaptive', $card['attachments'][0]['contentType']);

        $content = $card['attachments'][0]['content'];
        self::assertSame('AdaptiveCard', $content['type']);
        self::assertSame('1.4', $content['version']);
    }

    #[Test]
    public function pendingCardContainsEmployeeNameAndDateRange(): void
    {
        $card = $this->builder->buildPendingRequestCard($this->request);
        $factSet = $card['attachments'][0]['content']['body'][1];

        $factValues = array_column($factSet['facts'], 'value', 'title');

        self::assertSame('Jane Doe', $factValues['Mitarbeiter']);
        self::assertSame('Urlaub', $factValues['Art']);
        self::assertStringContainsString('06.07.2026', $factValues['Zeitraum']);
        self::assertStringContainsString('10.07.2026', $factValues['Zeitraum']);
    }

    #[Test]
    public function singleDayRequestShowsOneDateInsteadOfRange(): void
    {
        $company = new Company('Acme GmbH');
        $hq = new Location($company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $absenceType = new AbsenceType(company: $company, name: 'Urlaub', deductsFromLeave: true, requiresApproval: true, color: '#000');
        $employee = new Employee(
            company: $company,
            fullName: 'Max Muster',
            employeeNumber: 'EMP-002',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: null,
        );

        $sameDay = new LeaveRequest(
            employee: $employee,
            absenceType: $absenceType,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-06'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );

        $card = $this->builder->buildPendingRequestCard($sameDay);
        $factSet = $card['attachments'][0]['content']['body'][1];
        $factValues = array_column($factSet['facts'], 'value', 'title');

        self::assertSame('06.07.2026', $factValues['Zeitraum']);
    }

    #[Test]
    public function buildDecisionCardShowsApprovedColor(): void
    {
        $card = $this->builder->buildDecisionCard($this->request, 'approved', 'Maya Manager');
        $titleBlock = $card['attachments'][0]['content']['body'][0];

        self::assertSame('Good', $titleBlock['color']);
        self::assertStringContainsString('Genehmigt', $titleBlock['text']);
    }

    #[Test]
    public function buildDecisionCardShowsRejectedColor(): void
    {
        $card = $this->builder->buildDecisionCard($this->request, 'rejected', 'Maya Manager');
        $titleBlock = $card['attachments'][0]['content']['body'][0];

        self::assertSame('Attention', $titleBlock['color']);
        self::assertStringContainsString('Abgelehnt', $titleBlock['text']);
    }

    #[Test]
    public function buildDecisionCardContainsDecidedByFact(): void
    {
        $card = $this->builder->buildDecisionCard($this->request, 'approved', 'Maya Manager');
        $factSet = $card['attachments'][0]['content']['body'][1];
        $factValues = array_column($factSet['facts'], 'value', 'title');

        self::assertSame('Maya Manager', $factValues['Entschieden von']);
    }

    #[Test]
    public function buildDecisionCardOmitsDecidedByFactWhenEmpty(): void
    {
        $card = $this->builder->buildDecisionCard($this->request, 'approved', '');
        $factSet = $card['attachments'][0]['content']['body'][1];
        $factTitles = array_column($factSet['facts'], 'title');

        self::assertNotContains('Entschieden von', $factTitles);
    }
}
