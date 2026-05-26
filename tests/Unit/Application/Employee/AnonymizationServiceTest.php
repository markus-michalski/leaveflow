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

namespace App\Tests\Unit\Application\Employee;

use App\Application\Employee\AnonymizationNotDueException;
use App\Application\Employee\AnonymizationService;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AnonymizationService::class)]
#[CoversClass(AnonymizationNotDueException::class)]
final class AnonymizationServiceTest extends TestCase
{
    private Company $company;
    private Location $hq;
    private AnonymizationService $service;
    /** @var Stub&EmployeeRepository */
    private Stub $repository;
    /** @var Stub&EntityManagerInterface */
    private Stub $entityManager;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH', retentionPeriodMonths: 36);
        $this->hq = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->repository = $this->createStub(EmployeeRepository::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->service = new AnonymizationService(
            $this->repository,
            $this->entityManager,
            new MockClock(new \DateTimeImmutable('2026-05-21')),
        );
    }

    #[Test]
    public function anonymizesSingleEmployeeAfterRetentionPeriod(): void
    {
        // Left 2023-01-15, retention=36 months → due 2026-01-15 → past asOf 2026-05-21
        $employee = $this->buildExitedEmployee('2019-01-01', '2023-01-15', id: 42);

        $this->service->anonymize($employee);

        self::assertTrue($employee->isAnonymized());
    }

    #[Test]
    public function anonymizedNameFollowsPattern(): void
    {
        $employee = $this->buildExitedEmployee('2019-01-01', '2023-01-15', id: 42);

        $this->service->anonymize($employee);

        self::assertSame('Ehemaliger Mitarbeiter #42', $employee->getFullName());
    }

    #[Test]
    public function anonymizesLinkedUserWhenPresent(): void
    {
        $employee = $this->buildExitedEmployee('2019-01-01', '2023-01-15', id: 42);
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $this->setId($user, 99);
        $employee->linkUser($user);

        $this->service->anonymize($employee);

        self::assertSame('anonymized-99@leaveflow.local', $user->getEmail());
        self::assertNull($user->getPassword());
    }

    #[Test]
    public function throwsWhenEmployeeIsNotPersisted(): void
    {
        $employee = $this->buildExitedEmployee('2019-01-01', '2023-01-15');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unpersisted');

        $this->service->anonymize($employee);
    }

    #[Test]
    public function throwsWhenEmployeeIsStillActive(): void
    {
        $employee = new Employee(
            $this->company,
            'Active Employee',
            'EMP-A',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2020-01-01'),
        );
        $this->setId($employee, 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('still active');

        $this->service->anonymize($employee);
    }

    #[Test]
    public function throwsAnonymizationNotDueExceptionWhenRetentionNotElapsed(): void
    {
        // Left 2024-12-01, retention=36 months → due 2027-12-01 → future relative to 2026-05-21
        $employee = $this->buildExitedEmployee('2022-01-01', '2024-12-01', id: 5);

        $this->expectException(AnonymizationNotDueException::class);
        $this->expectExceptionMessage('retention period');

        $this->service->anonymize($employee);
    }

    #[Test]
    public function throwsWhenAlreadyAnonymized(): void
    {
        $employee = $this->buildExitedEmployee('2019-01-01', '2023-01-15', id: 7);
        $employee->anonymize('Ehemaliger Mitarbeiter #7', new \DateTimeImmutable('2026-02-01'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already anonymized');

        $this->service->anonymize($employee);
    }

    #[Test]
    public function findDueDelegatesToRepository(): void
    {
        $asOf = new \DateTimeImmutable('2026-05-21');
        $expected = [$this->buildExitedEmployee('2019-01-01', '2023-01-01', id: 1)];
        $this->repository->method('findDueForAnonymization')->willReturn($expected);

        $result = $this->service->findDue($asOf);

        self::assertSame($expected, $result);
    }

    private function buildExitedEmployee(string $joinedAt, string $leftAt, ?int $id = null): Employee
    {
        $employee = new Employee(
            $this->company,
            'Jane Doe',
            'EMP-'.uniqid(),
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable($joinedAt),
            null,
            new \DateTimeImmutable($leftAt),
        );

        if (null !== $id) {
            $this->setId($employee, $id);
        }

        return $employee;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
