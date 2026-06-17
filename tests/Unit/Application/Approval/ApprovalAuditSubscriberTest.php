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

namespace App\Tests\Unit\Application\Approval;

use App\Application\Approval\ApprovalAuditSubscriber;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

/**
 * Unit tests for ApprovalAuditSubscriber.
 *
 * Verifies the subscriber maps the Symfony Workflow CompletedEvent onto a
 * persisted LeaveRequestAuditEntry with correct from/to, actor, transition
 * name, optional reason, and clock-stamped occurredAt.
 */
#[CoversClass(ApprovalAuditSubscriber::class)]
final class ApprovalAuditSubscriberTest extends TestCase
{
    private LeaveRequest $request;
    private Employee $actor;

    protected function setUp(): void
    {
        $acme = new Company('Acme GmbH');
        $hq = new Location($acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $employee = new Employee(
            company: $acme,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->actor = new Employee(
            company: $acme,
            fullName: 'Max Mustermann',
            employeeNumber: 'EMP-0002',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
        );
        $urlaub = new AbsenceType(
            company: $acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->request = new LeaveRequest(
            employee: $employee,
            absenceType: $urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:30:00'),
        );
    }

    #[Test]
    public function persistsAuditEntryOnApproveEvent(): void
    {
        $clock = new MockClock('2026-05-02 10:15:00');
        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $subscriber = new ApprovalAuditSubscriber($entityManager, $clock);

        $event = $this->buildEvent(
            transitionName: 'approve',
            from: 'pending',
            to: 'approved',
            context: ['actor' => $this->actor],
        );

        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (LeaveRequestAuditEntry $entry): bool {
                self::assertSame($this->request, $entry->getLeaveRequest());
                self::assertSame($this->actor, $entry->getActor());
                self::assertSame('approve', $entry->getTransition());
                self::assertSame(LeaveRequestStatus::Pending, $entry->getFromStatus());
                self::assertSame(LeaveRequestStatus::Approved, $entry->getToStatus());
                self::assertSame('2026-05-02 10:15:00', $entry->getOccurredAt()->format('Y-m-d H:i:s'));
                self::assertNull($entry->getReason());

                return true;
            }));

        $subscriber($event);
    }

    #[Test]
    public function persistsReasonOnRejectEvent(): void
    {
        $clock = new MockClock('2026-05-02 10:15:00');
        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $subscriber = new ApprovalAuditSubscriber($entityManager, $clock);

        $event = $this->buildEvent(
            transitionName: 'reject',
            from: 'pending',
            to: 'rejected',
            context: ['actor' => $this->actor, 'reason' => 'Teambesetzung nicht ausreichend'],
        );

        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (LeaveRequestAuditEntry $entry): bool {
                self::assertSame('Teambesetzung nicht ausreichend', $entry->getReason());

                return true;
            }));

        $subscriber($event);
    }

    #[Test]
    public function persistsNullActorWhenContextMissesActor(): void
    {
        $clock = new MockClock('2026-05-02 10:15:00');
        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $subscriber = new ApprovalAuditSubscriber($entityManager, $clock);

        $event = $this->buildEvent(
            transitionName: 'cancel_pending',
            from: 'pending',
            to: 'cancelled',
            context: [],
        );

        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (LeaveRequestAuditEntry $entry): bool {
                self::assertNull($entry->getActor());

                return true;
            }));

        $subscriber($event);
    }

    #[Test]
    public function skipsEventsForUnrelatedSubjects(): void
    {
        $clock = new MockClock('2026-05-02 10:15:00');
        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $subscriber = new ApprovalAuditSubscriber($entityManager, $clock);

        $marking = new Marking(['approved' => 1]);
        $transition = new Transition('approve', 'pending', 'approved');
        $event = new CompletedEvent(
            subject: new \stdClass(),
            marking: $marking,
            transition: $transition,
            context: ['actor' => $this->actor],
        );

        $entityManager->expects(self::never())->method('persist');

        // @phpstan-ignore argument.type (intentional stdClass subject to test guard branch)
        $subscriber($event);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return CompletedEvent<LeaveRequest>
     */
    private function buildEvent(
        string $transitionName,
        string $from,
        string $to,
        array $context,
    ): CompletedEvent {
        $marking = new Marking([$to => 1]);
        $transition = new Transition($transitionName, $from, $to);

        return new CompletedEvent(
            subject: $this->request,
            marking: $marking,
            transition: $transition,
            context: $context,
        );
    }
}
