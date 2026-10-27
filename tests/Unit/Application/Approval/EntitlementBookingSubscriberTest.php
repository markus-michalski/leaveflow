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

use App\Application\Approval\EntitlementBookingSubscriber;
use App\Application\Approval\LeaveRequestEntitlementBooker;
use App\Application\Entitlement\EntitlementConsumer;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

#[CoversClass(EntitlementBookingSubscriber::class)]
final class EntitlementBookingSubscriberTest extends TestCase
{
    private LeaveRequest $request;
    private Employee $employee;
    private LeaveEntitlement $entitlement;
    private LeaveEntitlementRepository&MockObject $repository;

    protected function setUp(): void
    {
        $acme = new Company('Acme GmbH');
        $hq = new Location($acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->employee = new Employee(
            company: $acme,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $urlaub = new AbsenceType($acme, 'Urlaub', true, true, '#3B82F6');
        $this->request = new LeaveRequest(
            employee: $this->employee,
            absenceType: $urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-06'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
        );
        $this->request->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-07-06'), 8.0, LeaveDayStatus::Working),
        ]));
        $this->entitlement = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 240.0);
        $this->repository = $this->createMock(LeaveEntitlementRepository::class);
        $this->repository->method('findByEmployeeAndYear')->willReturn([$this->entitlement]);
    }

    #[Test]
    public function invokeConsumesEntitlementOnApprove(): void
    {
        $subscriber = new EntitlementBookingSubscriber($this->booker());
        $subscriber($this->buildEvent('approve', 'pending', 'approved'));

        self::assertSame(8.0, $this->entitlement->getHoursUsed());
    }

    #[Test]
    public function onConfirmCancelReleasesEntitlement(): void
    {
        $this->entitlement->consume(8.0);
        $subscriber = new EntitlementBookingSubscriber($this->booker());

        $subscriber->onConfirmCancel($this->buildEvent('confirm_cancel', 'cancel_requested', 'cancelled'));

        self::assertSame(0.0, $this->entitlement->getHoursUsed());
    }

    #[Test]
    public function skipsUnrelatedSubjects(): void
    {
        $this->repository->expects(self::never())->method('findByEmployeeAndYear');
        $subscriber = new EntitlementBookingSubscriber($this->booker());

        $marking = new Marking(['approved' => 1]);
        $transition = new Transition('approve', 'pending', 'approved');
        $event = new CompletedEvent(
            subject: new \stdClass(),
            marking: $marking,
            transition: $transition,
            context: [],
        );

        // @phpstan-ignore argument.type (intentional stdClass subject to test guard branch)
        $subscriber($event);
        // @phpstan-ignore argument.type (intentional stdClass subject to test guard branch)
        $subscriber->onConfirmCancel($event);
    }

    private function booker(): LeaveRequestEntitlementBooker
    {
        return new LeaveRequestEntitlementBooker(
            $this->repository,
            new EntitlementConsumer(),
            new MockClock('2026-05-01 12:00:00'),
        );
    }

    /** @return CompletedEvent<LeaveRequest> */
    private function buildEvent(string $name, string $from, string $to): CompletedEvent
    {
        $marking = new Marking([$to => 1]);
        $transition = new Transition($name, $from, $to);

        return new CompletedEvent(
            subject: $this->request,
            marking: $marking,
            transition: $transition,
            context: [],
        );
    }
}
