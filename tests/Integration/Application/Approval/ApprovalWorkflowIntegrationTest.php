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

namespace App\Tests\Integration\Application\Approval;

use App\Application\Approval\ApprovalWorkflow;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\Weekday;
use App\Domain\Repository\LeaveRequestAuditEntryRepository;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end verification that the Symfony Workflow config, the ApprovalWorkflow
 * service, and the ApprovalAuditSubscriber are wired together in the DI
 * container.
 *
 * The unit tests cover the service and subscriber in isolation; this test
 * confirms the actual container registration — a regression here would be
 * caught in CI instead of at the first real user click.
 */
#[CoversNothing]
final class ApprovalWorkflowIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ApprovalWorkflow $workflow;
    private LeaveRequestAuditEntryRepository $auditRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->workflow = self::getContainer()->get(ApprovalWorkflow::class);
        $this->auditRepository = self::getContainer()->get(LeaveRequestAuditEntryRepository::class);
    }

    #[Test]
    public function approveTransitionsStatusAndWritesAuditEntry(): void
    {
        [$request, $manager] = $this->createPendingRequestAndManagerWithEntitlement();

        $this->workflow->approve($request, $manager);
        $this->em->flush();

        self::assertSame(LeaveRequestStatus::Approved, $request->getStatus());

        $entries = $this->auditRepository->findByLeaveRequest($request);
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('approve', $entry->getTransition());
        self::assertSame(LeaveRequestStatus::Pending, $entry->getFromStatus());
        self::assertSame(LeaveRequestStatus::Approved, $entry->getToStatus());
        self::assertSame($manager, $entry->getActor());
    }

    #[Test]
    public function rejectPersistsReasonInAuditEntry(): void
    {
        [$request, $manager] = $this->createPendingRequestAndManager();

        $this->workflow->reject($request, $manager, 'Teambesetzung nicht ausreichend');
        $this->em->flush();

        $entries = $this->auditRepository->findByLeaveRequest($request);
        self::assertCount(1, $entries);
        self::assertSame('Teambesetzung nicht ausreichend', $entries[0]->getReason());
        self::assertSame(LeaveRequestStatus::Rejected, $entries[0]->getToStatus());
    }

    #[Test]
    public function approveDeductsEntitlementHours(): void
    {
        [$request, $manager, $entitlement] = $this->createPendingRequestAndManagerWithEntitlement();

        $this->workflow->approve($request, $manager);
        $this->em->flush();

        self::assertSame(40.0, $entitlement->getHoursUsed(), 'approve must book the full request hours.');
        self::assertSame(200.0, $entitlement->getHoursRemaining());
    }

    #[Test]
    public function confirmCancelReleasesEntitlementHours(): void
    {
        [$request, $manager, $entitlement] = $this->createPendingRequestAndManagerWithEntitlement();

        $this->workflow->approve($request, $manager);
        $this->em->flush();
        self::assertSame(40.0, $entitlement->getHoursUsed());

        $this->workflow->requestCancel($request, $request->getEmployee());
        $this->workflow->confirmCancel($request, $manager);
        $this->em->flush();

        self::assertSame(LeaveRequestStatus::Cancelled, $request->getStatus());
        self::assertSame(0.0, $entitlement->getHoursUsed(), 'confirm_cancel must refund booked hours.');
    }

    #[Test]
    public function denyCancelLeavesEntitlementHoursBooked(): void
    {
        [$request, $manager, $entitlement] = $this->createPendingRequestAndManagerWithEntitlement();

        $this->workflow->approve($request, $manager);
        $this->workflow->requestCancel($request, $request->getEmployee());
        $this->workflow->denyCancel($request, $manager, 'Teamplanung bleibt bestehen.');
        $this->em->flush();

        self::assertSame(LeaveRequestStatus::Approved, $request->getStatus());
        self::assertSame(40.0, $entitlement->getHoursUsed(), 'deny_cancel must leave hoursUsed unchanged.');
    }

    #[Test]
    public function approveOfNonDeductingTypeDoesNotTouchEntitlements(): void
    {
        [$request, $manager, $entitlement] = $this->createPendingRequestAndManagerWithEntitlement(deducting: false);

        $this->workflow->approve($request, $manager);
        $this->em->flush();

        self::assertSame(0.0, $entitlement->getHoursUsed());
    }

    /**
     * @return array{0: LeaveRequest, 1: Employee, 2: LeaveEntitlement}
     */
    private function createPendingRequestAndManagerWithEntitlement(bool $deducting = true): array
    {
        [$request, $manager] = $this->createPendingRequestAndManager($deducting);
        $entitlement = new LeaveEntitlement(
            $request->getEmployee(),
            2099,
            LeaveEntitlementType::Regular,
            240.0,
        );
        $this->em->persist($entitlement);
        $this->em->flush();

        return [$request, $manager, $entitlement];
    }

    /**
     * @return array{0: LeaveRequest, 1: Employee}
     */
    private function createPendingRequestAndManager(bool $deducting = true): array
    {
        $company = new Company('Integration GmbH');
        $this->em->persist($company);

        $location = new Location($company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($location);

        $schedule = WorkSchedule::autoDistribute(40.0, [
            Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday,
        ]);

        $employee = new Employee(
            company: $company,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($employee);

        $manager = new Employee(
            company: $company,
            fullName: 'Max Manager',
            employeeNumber: 'EMP-0002',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2018-01-01'),
        );
        $this->em->persist($manager);

        $urlaub = new AbsenceType(
            company: $company,
            name: $deducting ? 'Urlaub' : 'Krankheit',
            deductsFromLeave: $deducting,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->em->persist($urlaub);

        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $urlaub,
            startDate: new \DateTimeImmutable('2099-07-06'),
            endDate: new \DateTimeImmutable('2099-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2099-04-01 09:00:00'),
        );
        $request->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2099-07-06'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-07'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-08'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-09'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-10'), 8.0, LeaveDayStatus::Working),
        ]));
        $this->em->persist($request);
        $this->em->flush();

        return [$request, $manager];
    }
}
