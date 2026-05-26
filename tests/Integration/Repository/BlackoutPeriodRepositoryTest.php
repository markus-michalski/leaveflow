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

use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Repository\BlackoutPeriodRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for BlackoutPeriodRepository::findOverlapping.
 *
 * The overlap predicate (start_a <= end_b AND end_a >= start_b) plus the
 * scope predicate (company-wide OR matching department) is the core logic
 * the BlackoutPeriodChecker relies on. Tested against a real DB.
 */
#[CoversClass(BlackoutPeriodRepository::class)]
final class BlackoutPeriodRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BlackoutPeriodRepository $repository;
    private Company $acme;
    private Department $engineering;
    private Department $sales;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(BlackoutPeriodRepository::class);
        $this->seedCompanyAndDepartments();
    }

    #[Test]
    public function findOverlappingReturnsCompanyWideBlackoutWhenInRange(): void
    {
        $this->persistBlackout('2026-12-23', '2026-12-31', 'Werksferien');

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-12-27'),
            new \DateTimeImmutable('2027-01-02'),
        );

        self::assertCount(1, $result);
        self::assertSame('Werksferien', $result[0]->getReason());
    }

    #[Test]
    public function findOverlappingReturnsBlackoutWhenRangeStartsBeforeAndEndsInside(): void
    {
        $this->persistBlackout('2026-12-23', '2026-12-31', 'Werksferien');

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-12-20'),
            new \DateTimeImmutable('2026-12-25'),
        );

        self::assertCount(1, $result);
    }

    #[Test]
    public function findOverlappingExcludesBlackoutFullyBeforeRange(): void
    {
        $this->persistBlackout('2026-01-01', '2026-01-15', 'Old');

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        self::assertEmpty($result);
    }

    #[Test]
    public function findOverlappingExcludesBlackoutFullyAfterRange(): void
    {
        $this->persistBlackout('2026-12-23', '2026-12-31', 'Future');

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        self::assertEmpty($result);
    }

    #[Test]
    public function findOverlappingTreatsSharedBoundaryDayAsOverlap(): void
    {
        // Blackout ends on the same day the range starts → overlaps.
        $this->persistBlackout('2026-12-23', '2026-12-31', 'EdgeCase');

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-12-31'),
            new \DateTimeImmutable('2027-01-05'),
        );

        self::assertCount(1, $result);
    }

    #[Test]
    public function findOverlappingFiltersByDepartmentScope(): void
    {
        // Engineering blackout
        $this->persistBlackout('2026-07-01', '2026-07-14', 'Eng-Freeze', $this->engineering);
        // Sales blackout in the same window
        $this->persistBlackout('2026-07-01', '2026-07-14', 'Sales-Freeze', $this->sales);
        // Company-wide blackout
        $this->persistBlackout('2026-07-05', '2026-07-10', 'Town-Hall');

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
            $this->engineering,
        );

        // Engineering query should see Eng-Freeze + Town-Hall, NOT Sales-Freeze.
        $reasons = array_map(static fn (BlackoutPeriod $b) => $b->getReason(), $result);
        sort($reasons);
        self::assertSame(['Eng-Freeze', 'Town-Hall'], $reasons);
    }

    #[Test]
    public function findOverlappingWithoutDepartmentReturnsOnlyCompanyWide(): void
    {
        $this->persistBlackout('2026-07-01', '2026-07-14', 'Eng-Only', $this->engineering);
        $this->persistBlackout('2026-07-05', '2026-07-10', 'Company-Wide');

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
            null,
        );

        self::assertCount(1, $result);
        self::assertSame('Company-Wide', $result[0]->getReason());
    }

    #[Test]
    public function findOverlappingScopesToCompany(): void
    {
        $other = new Company('Other GmbH');
        $this->em->persist($other);
        $otherBlackout = new BlackoutPeriod(
            company: $other,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Other-Werksferien',
        );
        $this->em->persist($otherBlackout);
        $this->em->flush();

        $result = $this->repository->findOverlapping(
            $this->acme,
            new \DateTimeImmutable('2026-12-20'),
            new \DateTimeImmutable('2027-01-05'),
        );

        self::assertEmpty($result, 'Other company blackout must not leak into Acme query');
    }

    private function seedCompanyAndDepartments(): void
    {
        $this->acme = new Company('Acme GmbH');
        $hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $lead = new Employee(
            company: $this->acme,
            fullName: 'Engineering Lead',
            employeeNumber: 'EMP-ENG-LEAD',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
        );
        $this->engineering = new Department($this->acme, 'Engineering', lead: $lead);
        $this->sales = new Department($this->acme, 'Sales');

        $this->em->persist($this->acme);
        $this->em->persist($hq);
        $this->em->persist($lead);
        $this->em->persist($this->engineering);
        $this->em->persist($this->sales);
        $this->em->flush();
    }

    private function persistBlackout(
        string $start,
        string $end,
        string $reason,
        ?Department $department = null,
    ): BlackoutPeriod {
        $blackout = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            reason: $reason,
            department: $department,
        );
        $this->em->persist($blackout);
        $this->em->flush();

        return $blackout;
    }
}
