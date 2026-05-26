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
use App\Application\Notification\Teams\TeamsNotificationSubscriber;
use App\Application\Notification\Teams\TeamsNotifierInterface;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\UserRole;
use App\Domain\Event\LeaveRequestSubmittedEvent;
use App\Domain\Repository\CompanyRepository;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

#[CoversClass(TeamsNotificationSubscriber::class)]
class TeamsNotificationSubscriberTest extends TestCase
{
    private CompanyRepository&Stub $companyRepository;
    private TeamsNotifierInterface&MockObject $notifier;
    private LeaveRequest $request;
    private Company $company;

    protected function setUp(): void
    {
        $this->companyRepository = $this->createStub(CompanyRepository::class);
        $this->notifier = $this->createMock(TeamsNotifierInterface::class);

        $this->company = new Company('Acme GmbH');
        $hq = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $dept = new Department($this->company, 'Engineering');

        $user = new User($this->company, 'jane@acme.test', UserRole::Employee);
        $employee = new Employee(
            company: $this->company,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-001',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: $user,
        );
        $employee->assignToDepartment($dept);

        $absenceType = new AbsenceType(
            company: $this->company,
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

    private function makeSubscriber(): TeamsNotificationSubscriber
    {
        return new TeamsNotificationSubscriber(
            $this->companyRepository,
            $this->notifier,
            new TeamsCardBuilder(),
        );
    }

    #[Test]
    public function doesNotSendWhenTeamsDisabled(): void
    {
        $this->company->disableTeams();
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->never())->method('send');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function doesNotSendWhenNoWebhookUrl(): void
    {
        $this->company->enableTeams();
        $this->company->setTeamsWebhookUrl(null);
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->never())->method('send');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function sendsCardOnLeaveRequestSubmitted(): void
    {
        $this->company->enableTeams();
        $this->company->setTeamsWebhookUrl('https://outlook.office.com/webhook/test');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->once())
            ->method('send')
            ->with('https://outlook.office.com/webhook/test', $this->isArray());

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function sendsCardOnApproveTransition(): void
    {
        $this->company->enableTeams();
        $this->company->setTeamsWebhookUrl('https://outlook.office.com/webhook/test');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->once())->method('send');

        $event = new CompletedEvent(
            subject: $this->request,
            marking: new Marking(['approved' => 1]),
            transition: new Transition('approve', 'pending', 'approved'),
            workflow: null,
            context: [],
        );

        $this->makeSubscriber()->onWorkflowCompleted($event);
    }

    #[Test]
    public function sendsCardOnRejectTransition(): void
    {
        $this->company->enableTeams();
        $this->company->setTeamsWebhookUrl('https://outlook.office.com/webhook/test');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->once())->method('send');

        $event = new CompletedEvent(
            subject: $this->request,
            marking: new Marking(['rejected' => 1]),
            transition: new Transition('reject', 'pending', 'rejected'),
            workflow: null,
            context: [],
        );

        $this->makeSubscriber()->onWorkflowCompleted($event);
    }

    #[Test]
    public function ignoresOtherWorkflowTransitions(): void
    {
        $this->company->enableTeams();
        $this->company->setTeamsWebhookUrl('https://outlook.office.com/webhook/test');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->never())->method('send');

        $event = new CompletedEvent(
            subject: $this->request,
            marking: new Marking(['cancel_pending' => 1]),
            transition: new Transition('cancel_pending', 'pending', 'cancelled'),
            workflow: null,
            context: [],
        );

        $this->makeSubscriber()->onWorkflowCompleted($event);
    }

    #[Test]
    public function ignoresWorkflowEventForNonLeaveRequestSubject(): void
    {
        $this->company->enableTeams();
        $this->company->setTeamsWebhookUrl('https://outlook.office.com/webhook/test');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->never())->method('send');

        $event = new CompletedEvent(
            subject: new \stdClass(),
            marking: new Marking(['approved' => 1]),
            transition: new Transition('approve', 'pending', 'approved'),
            workflow: null,
            context: [],
        );

        $this->makeSubscriber()->onWorkflowCompleted($event);
    }

    #[Test]
    public function doesNotSendWhenCompanyNotFound(): void
    {
        $this->companyRepository->method('findOneBy')->willReturn(null);

        $this->notifier->expects($this->never())->method('send');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }
}
