<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\EntitlementExpiryCheckHandler;
use App\Application\Notification\EntitlementExpiryCheckMessage;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Unit tests for EntitlementExpiryCheckHandler.
 *
 * The handler is fired by the Symfony Scheduler (daily) via the
 * EntitlementExpiryCheckMessage marker. It loads all entitlements expiring
 * within 30 days that haven't been warned about yet, dispatches an
 * EntitlementExpiringSoon notification per recipient, and flips the
 * idempotency timestamp so the next run won't re-notify.
 */
#[CoversClass(EntitlementExpiryCheckHandler::class)]
final class EntitlementExpiryCheckHandlerTest extends TestCase
{
    private NotificationDispatcherInterface&MockObject $dispatcher;
    private LeaveEntitlementRepository&MockObject $entitlementRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MockClock $clock;
    private Company $company;
    private Location $hq;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $this->entitlementRepository = $this->createMock(LeaveEntitlementRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = new MockClock('2026-05-02 03:00:00', 'UTC');

        $this->company = new Company('Acme GmbH');
        $this->hq = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
    }

    private function createHandler(): EntitlementExpiryCheckHandler
    {
        // Always-enabled stub — toggle behavior tested separately.
        $jobConfig = $this->createMock(\App\Application\Scheduler\ScheduledJobConfigManagerInterface::class);
        $jobConfig->method('isEnabled')->willReturn(true);

        return new EntitlementExpiryCheckHandler(
            $this->entitlementRepository,
            $this->dispatcher,
            $this->entityManager,
            $jobConfig,
            $this->clock,
        );
    }

    private function createEmployeeWithUser(string $email, string $fullName, string $employeeNumber): Employee
    {
        $user = new User($this->company, $email, UserRole::Employee);

        return new Employee(
            company: $this->company,
            fullName: $fullName,
            employeeNumber: $employeeNumber,
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: $user,
        );
    }

    #[Test]
    public function dispatchesNotificationForEachExpiringEntitlement(): void
    {
        $jane = $this->createEmployeeWithUser('jane@acme.test', 'Jane Doe', 'EMP-0001');
        $erik = $this->createEmployeeWithUser('erik@acme.test', 'Erik Erikson', 'EMP-0002');

        $janeCarryover = new LeaveEntitlement(
            $jane,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-05-20'),
        );
        $erikCarryover = new LeaveEntitlement(
            $erik,
            2026,
            LeaveEntitlementType::Carryover,
            8.0,
            new \DateTimeImmutable('2026-05-31'),
        );

        $this->entitlementRepository->expects(self::once())
            ->method('findExpiringWithoutWarning')
            ->willReturn([$janeCarryover, $erikCarryover]);

        $captured = [];
        $this->dispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (NotificationType $type, User $recipient, array $payload) use (&$captured) {
                $captured[] = ['type' => $type, 'recipient' => $recipient, 'payload' => $payload];

                return $this->createStub(\App\Domain\Entity\Notification::class);
            });

        $this->entityManager->expects(self::once())->method('flush');

        ($this->createHandler())(new EntitlementExpiryCheckMessage());

        self::assertCount(2, $captured);
        self::assertSame(NotificationType::EntitlementExpiringSoon, $captured[0]['type']);
        self::assertSame('jane@acme.test', $captured[0]['recipient']->getEmail());
        self::assertSame('erik@acme.test', $captured[1]['recipient']->getEmail());
    }

    #[Test]
    public function payloadIncludesDaysRemainingAndExpiryDate(): void
    {
        $jane = $this->createEmployeeWithUser('jane@acme.test', 'Jane Doe', 'EMP-0001');
        $entitlement = new LeaveEntitlement(
            $jane,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-05-20'),
        );

        $this->entitlementRepository->method('findExpiringWithoutWarning')
            ->willReturn([$entitlement]);

        $capturedPayload = null;
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (NotificationType $type, User $recipient, array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return $this->createStub(\App\Domain\Entity\Notification::class);
            });

        ($this->createHandler())(new EntitlementExpiryCheckMessage());

        self::assertNotNull($capturedPayload);
        // 2026-05-02 -> 2026-05-20 = 18 days
        self::assertSame(18, $capturedPayload['daysRemaining']);
        self::assertSame('20.05.2026', $capturedPayload['expiresAt']);
        self::assertSame('16,00', $capturedPayload['hoursRemaining']);
        self::assertSame(2026, $capturedPayload['year']);
    }

    #[Test]
    public function marksWarningAsSentToPreventReNotification(): void
    {
        $jane = $this->createEmployeeWithUser('jane@acme.test', 'Jane Doe', 'EMP-0001');
        $entitlement = new LeaveEntitlement(
            $jane,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-05-20'),
        );

        $this->entitlementRepository->method('findExpiringWithoutWarning')
            ->willReturn([$entitlement]);
        $this->dispatcher->method('dispatch')
            ->willReturn($this->createStub(\App\Domain\Entity\Notification::class));

        ($this->createHandler())(new EntitlementExpiryCheckMessage());

        $stamp = $entitlement->getExpiryWarningSentAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $stamp);
        self::assertSame('2026-05-02 03:00:00', $stamp->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function skipsEntitlementsWhereEmployeeHasNoUserAccount(): void
    {
        // Phase 2 retention case: ex-employee data kept, User unlinked.
        $hannah = new Employee(
            company: $this->company,
            fullName: 'Hannah History',
            employeeNumber: 'EMP-0003',
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
            user: null,
        );
        $entitlement = new LeaveEntitlement(
            $hannah,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-05-20'),
        );

        $this->entitlementRepository->method('findExpiringWithoutWarning')
            ->willReturn([$entitlement]);

        $this->dispatcher->expects(self::never())->method('dispatch');

        ($this->createHandler())(new EntitlementExpiryCheckMessage());

        // Stamp must remain null so a future re-link of the User still triggers
        // a warning rather than silently swallowing it.
        self::assertNull($entitlement->getExpiryWarningSentAt());
    }

    #[Test]
    public function handlesEmptyResultGracefully(): void
    {
        $this->entitlementRepository->expects(self::once())
            ->method('findExpiringWithoutWarning')
            ->willReturn([]);

        $this->dispatcher->expects(self::never())->method('dispatch');
        // Empty workload still flushes — Doctrine no-ops on no changes.
        $this->entityManager->expects(self::once())->method('flush');

        ($this->createHandler())(new EntitlementExpiryCheckMessage());
    }

    #[Test]
    public function passesTodayToRepositoryQuery(): void
    {
        $captured = null;
        $this->entitlementRepository->expects(self::once())
            ->method('findExpiringWithoutWarning')
            ->willReturnCallback(static function (\DateTimeImmutable $today) use (&$captured): array {
                $captured = $today;

                return [];
            });

        ($this->createHandler())(new EntitlementExpiryCheckMessage());

        self::assertNotNull($captured);
        self::assertSame('2026-05-02', $captured->format('Y-m-d'));
    }
}
