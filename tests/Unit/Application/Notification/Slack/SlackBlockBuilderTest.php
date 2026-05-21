<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification\Slack;

use App\Application\Notification\Slack\SlackBlockBuilder;
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

#[CoversClass(SlackBlockBuilder::class)]
class SlackBlockBuilderTest extends TestCase
{
    private SlackBlockBuilder $builder;
    private LeaveRequest $request;

    protected function setUp(): void
    {
        $this->builder = new SlackBlockBuilder();

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
    public function pendingBlocksContainHeaderAndSection(): void
    {
        $blocks = $this->builder->buildPendingRequestBlocks($this->request);

        self::assertSame('header', $blocks[0]['type']);
        self::assertSame('section', $blocks[1]['type']);
        self::assertSame('actions', $blocks[2]['type']);
    }

    #[Test]
    public function pendingBlocksContainApproveAndRejectButtons(): void
    {
        $blocks = $this->builder->buildPendingRequestBlocks($this->request);
        $elements = $blocks[2]['elements'];

        self::assertCount(2, $elements);
        self::assertStringStartsWith('approve:', $elements[0]['action_id']);
        self::assertStringStartsWith('reject:', $elements[1]['action_id']);
        self::assertSame('primary', $elements[0]['style']);
        self::assertSame('danger', $elements[1]['style']);
    }

    #[Test]
    public function pendingBlocksContainEmployeeAndDateRange(): void
    {
        $blocks = $this->builder->buildPendingRequestBlocks($this->request);
        $fields = $blocks[1]['fields'];

        $fieldTexts = array_column($fields, 'text');
        self::assertStringContainsString('Jane Doe', implode(' ', $fieldTexts));
        self::assertStringContainsString('06.07.2026', implode(' ', $fieldTexts));
        self::assertStringContainsString('10.07.2026', implode(' ', $fieldTexts));
    }

    #[Test]
    public function pendingBlocksSingleDayShowsOnlyOneDate(): void
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

        $blocks = $this->builder->buildPendingRequestBlocks($sameDay);
        $fields = $blocks[1]['fields'];
        $fieldTexts = array_column($fields, 'text');
        $dateField = array_filter($fieldTexts, static fn ($t) => str_contains($t, '2026'));

        self::assertStringNotContainsString('–', implode('', $dateField));
        self::assertStringContainsString('06.07.2026', implode('', $dateField));
    }

    #[Test]
    public function decisionBlocksApprovedUsesCheckmarkEmoji(): void
    {
        $blocks = $this->builder->buildDecisionBlocks($this->request, 'approved', 'Maya Manager');
        $headerText = $blocks[0]['text']['text'];

        self::assertStringContainsString(':white_check_mark:', $headerText);
        self::assertStringContainsString('Genehmigt', $headerText);
    }

    #[Test]
    public function decisionBlocksRejectedUsesXEmoji(): void
    {
        $blocks = $this->builder->buildDecisionBlocks($this->request, 'rejected', 'Maya Manager');
        $headerText = $blocks[0]['text']['text'];

        self::assertStringContainsString(':x:', $headerText);
        self::assertStringContainsString('Abgelehnt', $headerText);
    }

    #[Test]
    public function decisionBlocksContainsDecidedBy(): void
    {
        $blocks = $this->builder->buildDecisionBlocks($this->request, 'approved', 'Maya Manager');
        $fields = $blocks[1]['fields'];
        $fieldTexts = array_column($fields, 'text');

        self::assertStringContainsString('Maya Manager', implode(' ', $fieldTexts));
    }

    #[Test]
    public function decisionBlocksOmitsDecidedByWhenEmpty(): void
    {
        $blocks = $this->builder->buildDecisionBlocks($this->request, 'approved', '');
        $fields = $blocks[1]['fields'];
        $fieldTexts = array_column($fields, 'text');

        self::assertStringNotContainsString('Entschieden von', implode(' ', $fieldTexts));
    }

    #[Test]
    public function employeeDmBlocksContainStatusAndDateRange(): void
    {
        $blocks = $this->builder->buildEmployeeDmBlocks($this->request, 'approved');
        $text = $blocks[0]['text']['text'];

        self::assertStringContainsString(':white_check_mark:', $text);
        self::assertStringContainsString('genehmigt', $text);
        self::assertStringContainsString('06.07.2026', $text);
    }

    #[Test]
    public function leaveRequestModalHasRequiredBlocks(): void
    {
        $options = [
            ['text' => ['type' => 'plain_text', 'text' => 'Urlaub'], 'value' => '1'],
        ];
        $modal = $this->builder->buildLeaveRequestModal($options);

        self::assertSame('modal', $modal['type']);
        self::assertSame('leave_request_submit', $modal['callback_id']);

        $blockIds = array_column($modal['blocks'], 'block_id');
        self::assertContains('absence_type', $blockIds);
        self::assertContains('start_date', $blockIds);
        self::assertContains('end_date', $blockIds);
    }

    #[Test]
    public function mrkdwnSpecialCharsInEmployeeNameAreEscaped(): void
    {
        $company = new Company('Acme GmbH');
        $hq = new Location($company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $absenceType = new AbsenceType(company: $company, name: '<Script>', deductsFromLeave: true, requiresApproval: true, color: '#000');
        $employee = new Employee(
            company: $company,
            fullName: '<@U123456> & "Bob"',
            employeeNumber: 'EMP-003',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: null,
        );

        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $absenceType,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-06'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );

        $blocks = $this->builder->buildPendingRequestBlocks($request);
        $allText = implode(' ', array_column($blocks[1]['fields'], 'text'));

        self::assertStringNotContainsString('<@U123456>', $allText);
        self::assertStringContainsString('&lt;@U123456&gt;', $allText);
        self::assertStringContainsString('&amp;', $allText);

        $dmBlocks = $this->builder->buildEmployeeDmBlocks($request, 'approved');
        self::assertStringNotContainsString('<Script>', $dmBlocks[0]['text']['text']);
        self::assertStringContainsString('&lt;Script&gt;', $dmBlocks[0]['text']['text']);
    }
}
