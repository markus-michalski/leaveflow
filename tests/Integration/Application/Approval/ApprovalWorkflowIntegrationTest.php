<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application\Approval;

use App\Application\Approval\ApprovalWorkflow;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
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
        [$request, $manager] = $this->createPendingRequestAndManager();

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

    /**
     * @return array{0: LeaveRequest, 1: Employee}
     */
    private function createPendingRequestAndManager(): array
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
            name: 'Urlaub',
            deductsFromLeave: true,
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
