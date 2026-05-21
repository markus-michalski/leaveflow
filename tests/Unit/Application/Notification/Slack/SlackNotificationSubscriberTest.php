<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification\Slack;

use App\Application\Notification\Slack\SlackBlockBuilder;
use App\Application\Notification\Slack\SlackNotificationSubscriber;
use App\Application\Notification\Slack\SlackNotifierInterface;
use App\Application\Security\EncryptionServiceInterface;
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

#[CoversClass(SlackNotificationSubscriber::class)]
class SlackNotificationSubscriberTest extends TestCase
{
    private CompanyRepository&Stub $companyRepository;
    private SlackNotifierInterface&MockObject $notifier;
    private EncryptionServiceInterface&Stub $encryption;
    private LeaveRequest $request;
    private Company $company;

    protected function setUp(): void
    {
        $this->companyRepository = $this->createStub(CompanyRepository::class);
        $this->notifier = $this->createMock(SlackNotifierInterface::class);
        $this->encryption = $this->createStub(EncryptionServiceInterface::class);

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

    private function makeSubscriber(): SlackNotificationSubscriber
    {
        return new SlackNotificationSubscriber(
            $this->companyRepository,
            $this->notifier,
            new SlackBlockBuilder(),
            $this->encryption,
        );
    }

    private function enableSlack(?string $token = 'encrypted-token', ?string $channel = 'C0123'): void
    {
        $this->company->enableSlack();
        $this->company->setSlackBotToken($token);
        $this->company->setSlackChannelId($channel);
        $this->company->setSlackSigningSecret('encrypted-secret');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);
        $this->encryption->method('tryDecrypt')->willReturn('xoxb-decrypted');
    }

    #[Test]
    public function doesNotSendWhenSlackDisabled(): void
    {
        $this->company->disableSlack();
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->never())->method('postMessage');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function doesNotSendWhenNoToken(): void
    {
        $this->company->enableSlack();
        $this->company->setSlackBotToken(null);
        $this->company->setSlackChannelId('C0123');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->never())->method('postMessage');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function doesNotSendWhenNoChannel(): void
    {
        $this->company->enableSlack();
        $this->company->setSlackBotToken('encrypted-token');
        $this->company->setSlackChannelId(null);
        $this->companyRepository->method('findOneBy')->willReturn($this->company);

        $this->notifier->expects($this->never())->method('postMessage');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function doesNotSendWhenDecryptionFails(): void
    {
        $this->company->enableSlack();
        $this->company->setSlackBotToken('corrupted-token');
        $this->company->setSlackChannelId('C0123');
        $this->companyRepository->method('findOneBy')->willReturn($this->company);
        $this->encryption->method('tryDecrypt')->willReturn(null);

        $this->notifier->expects($this->never())->method('postMessage');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function sendsMessageOnLeaveRequestSubmitted(): void
    {
        $this->enableSlack();

        $this->notifier->expects($this->once())
            ->method('postMessage')
            ->with('xoxb-decrypted', 'C0123', $this->isArray(), $this->isString());

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }

    #[Test]
    public function sendsMessageOnApproveTransition(): void
    {
        $this->enableSlack();

        $this->notifier->expects($this->atLeastOnce())->method('postMessage');

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
    public function sendsMessageOnRejectTransition(): void
    {
        $this->enableSlack();

        $this->notifier->expects($this->atLeastOnce())->method('postMessage');

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
    public function ignoresNonApproveRejectTransitions(): void
    {
        $this->enableSlack();

        $this->notifier->expects($this->never())->method('postMessage');

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
        $this->enableSlack();

        $this->notifier->expects($this->never())->method('postMessage');

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

        $this->notifier->expects($this->never())->method('postMessage');

        $this->makeSubscriber()->onLeaveRequestSubmitted(new LeaveRequestSubmittedEvent($this->request));
    }
}
