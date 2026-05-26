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

namespace App\Tests\Integration\Repository;

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
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\Repository\LeaveRequestDayRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for the SQL aggregates that drive the admin statistics
 * dashboard. The PHP-side bucketing happens after the rows are hydrated, so
 * the assertions cover both the SQL filter clauses (status, isIllnessTracking,
 * date range, company scoping) and the per-month / per-employee grouping.
 */
#[CoversClass(LeaveRequestDayRepository::class)]
#[CoversClass(LeaveRequestRepository::class)]
#[CoversClass(LeaveEntitlementRepository::class)]
final class StatisticsAggregateQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LeaveRequestDayRepository $dayRepo;
    private LeaveRequestRepository $requestRepo;
    private LeaveEntitlementRepository $entitlementRepo;
    private Company $acme;
    private Company $other;
    private Employee $alice;
    private Employee $bob;
    private Employee $otherEmployee;
    private AbsenceType $vacation;
    private AbsenceType $illness;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->dayRepo = self::getContainer()->get(LeaveRequestDayRepository::class);
        $this->requestRepo = self::getContainer()->get(LeaveRequestRepository::class);
        $this->entitlementRepo = self::getContainer()->get(LeaveEntitlementRepository::class);
        $this->seed();
    }

    #[Test]
    public function sumIllnessHoursByEmployeeForCompanyGroupsByEmployeeAndExcludesVacation(): void
    {
        // Alice: 16h illness in March
        $this->persistRequest($this->alice, $this->illness, '2026-03-02', '2026-03-03', LeaveRequestStatus::Recorded);
        // Bob: 8h illness in March + 8h illness in May
        $this->persistRequest($this->bob, $this->illness, '2026-03-10', '2026-03-10', LeaveRequestStatus::Recorded);
        $this->persistRequest($this->bob, $this->illness, '2026-05-04', '2026-05-04', LeaveRequestStatus::Recorded);
        // Alice: vacation — must NOT count as illness
        $this->persistRequest($this->alice, $this->vacation, '2026-04-06', '2026-04-10', LeaveRequestStatus::Approved);

        $result = $this->dayRepo->sumIllnessHoursByEmployeeForCompany(
            $this->acme,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertCount(2, $result);
        self::assertSame(16.0, $result[(int) $this->alice->getId()]);
        self::assertSame(16.0, $result[(int) $this->bob->getId()]);
    }

    #[Test]
    public function sumIllnessHoursByEmployeeForCompanyExcludesOtherCompanies(): void
    {
        $this->persistRequest($this->otherEmployee, $this->illness, '2026-03-02', '2026-03-03', LeaveRequestStatus::Recorded);
        $this->persistRequest($this->alice, $this->illness, '2026-03-02', '2026-03-02', LeaveRequestStatus::Recorded);

        $result = $this->dayRepo->sumIllnessHoursByEmployeeForCompany(
            $this->acme,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertArrayHasKey($this->alice->getId(), $result);
        self::assertArrayNotHasKey($this->otherEmployee->getId(), $result);
    }

    #[Test]
    public function sumIllnessHoursByEmployeeForCompanyRespectsDateRange(): void
    {
        // Out of range — must be excluded
        $this->persistRequest($this->alice, $this->illness, '2026-12-29', '2026-12-30', LeaveRequestStatus::Recorded);
        // In range
        $this->persistRequest($this->bob, $this->illness, '2026-06-15', '2026-06-15', LeaveRequestStatus::Recorded);

        $result = $this->dayRepo->sumIllnessHoursByEmployeeForCompany(
            $this->acme,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        self::assertArrayNotHasKey((int) $this->alice->getId(), $result);
        self::assertSame(8.0, $result[(int) $this->bob->getId()]);
    }

    #[Test]
    public function sumApprovedDeductingHoursByMonthBucketsByMonth(): void
    {
        $this->persistRequest($this->alice, $this->vacation, '2026-03-02', '2026-03-06', LeaveRequestStatus::Approved); // 5 × 8h = 40h
        $this->persistRequest($this->bob, $this->vacation, '2026-03-09', '2026-03-09', LeaveRequestStatus::Approved);   // 8h
        $this->persistRequest($this->alice, $this->vacation, '2026-07-13', '2026-07-17', LeaveRequestStatus::Approved); // 40h

        $result = $this->dayRepo->sumApprovedDeductingHoursByMonth($this->acme, 2026);

        self::assertSame(48.0, $result[3]);
        self::assertSame(40.0, $result[7]);
        self::assertArrayNotHasKey(1, $result);
    }

    #[Test]
    public function sumApprovedDeductingHoursByMonthExcludesPendingAndIllness(): void
    {
        // Pending vacation — must be excluded
        $this->persistRequest($this->alice, $this->vacation, '2026-04-06', '2026-04-06', LeaveRequestStatus::Pending);
        // Approved illness (illness is non-deducting in seed) — must be excluded
        $this->persistRequest($this->bob, $this->illness, '2026-04-06', '2026-04-06', LeaveRequestStatus::Recorded);
        // Approved vacation — counts
        $this->persistRequest($this->alice, $this->vacation, '2026-04-13', '2026-04-13', LeaveRequestStatus::Approved);

        $result = $this->dayRepo->sumApprovedDeductingHoursByMonth($this->acme, 2026);

        self::assertSame(8.0, $result[4] ?? 0.0);
    }

    #[Test]
    public function countAwaitingDecisionInCompanyCountsPendingAndCancelRequested(): void
    {
        $this->persistRequest($this->alice, $this->vacation, '2026-04-06', '2026-04-06', LeaveRequestStatus::Pending);
        $this->persistRequest($this->bob, $this->vacation, '2026-04-13', '2026-04-13', LeaveRequestStatus::CancelRequested);
        $this->persistRequest($this->alice, $this->vacation, '2026-05-04', '2026-05-04', LeaveRequestStatus::Approved);
        $this->persistRequest($this->bob, $this->vacation, '2026-05-11', '2026-05-11', LeaveRequestStatus::Rejected);

        self::assertSame(2, $this->requestRepo->countAwaitingDecisionInCompany($this->acme));
    }

    #[Test]
    public function countAwaitingDecisionInCompanyScopesToCompany(): void
    {
        $this->persistRequest($this->otherEmployee, $this->illness, '2026-04-06', '2026-04-06', LeaveRequestStatus::Pending);

        self::assertSame(0, $this->requestRepo->countAwaitingDecisionInCompany($this->acme));
    }

    #[Test]
    public function findOverduePendingInCompanyReturnsOnlyRequestsBeyondThreshold(): void
    {
        // Pending, requested 10 days ago — beyond threshold
        $old = $this->persistRequest($this->alice, $this->vacation, '2026-06-15', '2026-06-19', LeaveRequestStatus::Pending);
        (new \ReflectionProperty(LeaveRequest::class, 'requestedAt'))
            ->setValue($old, new \DateTimeImmutable('2026-04-30 09:00:00'));
        // Pending, requested today — fresh
        $fresh = $this->persistRequest($this->bob, $this->vacation, '2026-06-22', '2026-06-26', LeaveRequestStatus::Pending);
        (new \ReflectionProperty(LeaveRequest::class, 'requestedAt'))
            ->setValue($fresh, new \DateTimeImmutable('2026-05-09 09:00:00'));
        // Approved — must be excluded even when old
        $approved = $this->persistRequest($this->alice, $this->vacation, '2026-07-06', '2026-07-10', LeaveRequestStatus::Approved);
        (new \ReflectionProperty(LeaveRequest::class, 'requestedAt'))
            ->setValue($approved, new \DateTimeImmutable('2026-04-01 09:00:00'));
        $this->em->flush();

        $now = new \DateTimeImmutable('2026-05-10 12:00:00');
        $result = $this->requestRepo->findOverduePendingInCompany($this->acme, $now, 5);

        self::assertCount(1, $result);
        self::assertSame($old->getId(), $result[0]->getId());
    }

    #[Test]
    public function findOverduePendingInCompanyExcludesOtherCompanies(): void
    {
        $other = $this->persistRequest($this->otherEmployee, $this->illness, '2026-06-15', '2026-06-15', LeaveRequestStatus::Pending);
        (new \ReflectionProperty(LeaveRequest::class, 'requestedAt'))
            ->setValue($other, new \DateTimeImmutable('2026-04-30 09:00:00'));
        $this->em->flush();

        $now = new \DateTimeImmutable('2026-05-10 12:00:00');
        $result = $this->requestRepo->findOverduePendingInCompany($this->acme, $now, 5);

        self::assertEmpty($result);
    }

    #[Test]
    public function findCarryoversExpiringWithinReturnsOnlyMatchingCarryovers(): void
    {
        // Carryover within horizon — should be included
        $within = new LeaveEntitlement(
            $this->alice,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-08-01'),
        );
        // Carryover beyond horizon
        $beyond = new LeaveEntitlement(
            $this->bob,
            2026,
            LeaveEntitlementType::Carryover,
            8.0,
            new \DateTimeImmutable('2026-12-15'),
        );
        // Drained carryover within horizon — must be excluded because
        // hoursRemaining = 0
        $drained = new LeaveEntitlement(
            $this->bob,
            2025,
            LeaveEntitlementType::Carryover,
            8.0,
            new \DateTimeImmutable('2026-07-30'),
        );
        $drained->consume(8.0);
        // Regular entitlement — never carries an expiry
        $regular = new LeaveEntitlement(
            $this->alice,
            2026,
            LeaveEntitlementType::Regular,
            240.0,
        );

        foreach ([$within, $beyond, $drained, $regular] as $ent) {
            $this->em->persist($ent);
        }
        $this->em->flush();

        $today = new \DateTimeImmutable('2026-05-10');
        $result = $this->entitlementRepo->findCarryoversExpiringWithin($this->acme, $today, 90);

        self::assertCount(1, $result);
        self::assertSame($within->getId(), $result[0]->getId());
    }

    #[Test]
    public function findCarryoversExpiringWithinScopesToCompany(): void
    {
        $other = new LeaveEntitlement(
            $this->otherEmployee,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-08-01'),
        );
        $this->em->persist($other);
        $this->em->flush();

        $today = new \DateTimeImmutable('2026-05-10');
        $result = $this->entitlementRepo->findCarryoversExpiringWithin($this->acme, $today, 90);

        self::assertEmpty($result);
    }

    private function seed(): void
    {
        $this->acme = new Company('Acme GmbH');
        $this->other = new Company('Other GmbH');
        $hq = new Location($this->acme, 'HQ', 'DE', 'DE-BY', 'München');
        $otherLoc = new Location($this->other, 'HQ', 'DE', 'DE-BE', 'Berlin');

        $schedule = WorkSchedule::standardFullTime();
        $this->alice = new Employee(
            $this->acme,
            'Alice',
            'EMP-1',
            $hq,
            $schedule,
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->bob = new Employee(
            $this->acme,
            'Bob',
            'EMP-2',
            $hq,
            $schedule,
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->otherEmployee = new Employee(
            $this->other,
            'Carol',
            'EMP-3',
            $otherLoc,
            $schedule,
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->vacation = new AbsenceType(
            $this->acme,
            'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->illness = new AbsenceType(
            $this->acme,
            'Krankheit',
            deductsFromLeave: false,
            requiresApproval: false,
            color: '#EF4444',
            illnessTracking: true,
        );
        $otherIllness = new AbsenceType(
            $this->other,
            'Krankheit',
            deductsFromLeave: false,
            requiresApproval: false,
            color: '#EF4444',
            illnessTracking: true,
        );

        $this->em->persist($this->acme);
        $this->em->persist($this->other);
        $this->em->persist($hq);
        $this->em->persist($otherLoc);
        $this->em->persist($this->alice);
        $this->em->persist($this->bob);
        $this->em->persist($this->otherEmployee);
        $this->em->persist($this->vacation);
        $this->em->persist($this->illness);
        $this->em->persist($otherIllness);
        $this->em->flush();
    }

    private function persistRequest(
        Employee $employee,
        AbsenceType $type,
        string $start,
        string $end,
        LeaveRequestStatus $forcedStatus,
    ): LeaveRequest {
        $startDt = new \DateTimeImmutable($start);
        $endDt = new \DateTimeImmutable($end);

        // For the cross-company case the AbsenceType passed must match the
        // employee's company — fall back to looking up the right one.
        if ($type->getCompany() !== $employee->getCompany()) {
            $type = $this->em->getRepository(AbsenceType::class)->findOneBy([
                'company' => $employee->getCompany(),
                'name' => $type->getName(),
            ]) ?? $type;
        }

        $request = new LeaveRequest(
            $employee,
            $type,
            $startDt,
            $endDt,
            LeaveDayType::FullDay,
            new \DateTimeImmutable($start.' 09:00:00'),
        );

        // Build a flat 8h-per-weekday breakdown without holiday handling so
        // the test stays focused on the aggregate queries.
        $days = [];
        for ($cursor = $startDt; $cursor <= $endDt; $cursor = $cursor->modify('+1 day')) {
            $days[] = new LeaveDay($cursor, 8.0, LeaveDayStatus::Working);
        }
        $request->applyBreakdown(new LeaveBreakdown($days));

        // Force status via the workflow setter — the construct-time default
        // wouldn't always match what we need (Approved/CancelRequested/etc.).
        $request->setStatus($forcedStatus);

        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }
}
