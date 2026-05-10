<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveEntitlementAuditEntry;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementAuditChangeType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeaveEntitlementAuditEntry::class)]
final class LeaveEntitlementAuditEntryTest extends TestCase
{
    private LeaveEntitlement $entitlement;
    private Employee $admin;

    protected function setUp(): void
    {
        $company = new Company('Acme', 36);
        $location = new Location($company, 'HQ', 'DE', 'DE-BY', 'München');
        $employee = new Employee(
            $company,
            'Erika',
            'EMP-1',
            $location,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->admin = new Employee(
            $company,
            'Anna Admin',
            'EMP-2',
            $location,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2020-01-01'),
        );
        $this->entitlement = new LeaveEntitlement(
            $employee,
            2026,
            LeaveEntitlementType::Regular,
            240.0,
        );
    }

    #[Test]
    public function forHoursAdjustmentRecordsOldAndNewGrant(): void
    {
        $entry = LeaveEntitlementAuditEntry::forHoursAdjustment(
            entitlement: $this->entitlement,
            actor: $this->admin,
            oldHoursGranted: 240.0,
            newHoursGranted: 248.0,
            occurredAt: new \DateTimeImmutable('2026-05-10 09:30:00'),
            reason: 'Korrektur: Überstunden-Übertrag aus 2025',
        );

        self::assertSame(LeaveEntitlementAuditChangeType::HoursGrantedAdjusted, $entry->getChangeType());
        self::assertSame(240.0, $entry->getOldHoursGranted());
        self::assertSame(248.0, $entry->getNewHoursGranted());
        self::assertNull($entry->getOldExpiresAt());
        self::assertNull($entry->getNewExpiresAt());
        self::assertSame('Korrektur: Überstunden-Übertrag aus 2025', $entry->getReason());
        self::assertSame($this->admin, $entry->getActor());
        self::assertSame($this->entitlement, $entry->getEntitlement());
    }

    #[Test]
    public function forHoursAdjustmentRejectsEmptyReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reason must not be empty');

        LeaveEntitlementAuditEntry::forHoursAdjustment(
            entitlement: $this->entitlement,
            actor: $this->admin,
            oldHoursGranted: 240.0,
            newHoursGranted: 248.0,
            occurredAt: new \DateTimeImmutable('2026-05-10'),
            reason: '   ',
        );
    }

    #[Test]
    public function forHoursAdjustmentRejectsUnchangedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must record an actual change');

        LeaveEntitlementAuditEntry::forHoursAdjustment(
            entitlement: $this->entitlement,
            actor: $this->admin,
            oldHoursGranted: 240.0,
            newHoursGranted: 240.0,
            occurredAt: new \DateTimeImmutable('2026-05-10'),
            reason: 'redundant edit',
        );
    }

    #[Test]
    public function forExpiryAdjustmentRecordsOldAndNewDate(): void
    {
        $oldDate = new \DateTimeImmutable('2027-03-31');
        $newDate = new \DateTimeImmutable('2027-06-30');

        $entry = LeaveEntitlementAuditEntry::forExpiryAdjustment(
            entitlement: $this->entitlement,
            actor: $this->admin,
            oldExpiresAt: $oldDate,
            newExpiresAt: $newDate,
            occurredAt: new \DateTimeImmutable('2026-05-10'),
            reason: 'BAG-Verlängerung wegen Krankheit',
        );

        self::assertSame(LeaveEntitlementAuditChangeType::ExpiresAtAdjusted, $entry->getChangeType());
        self::assertNull($entry->getOldHoursGranted());
        self::assertNull($entry->getNewHoursGranted());
        self::assertSame('2027-03-31', $entry->getOldExpiresAt()?->format('Y-m-d'));
        self::assertSame('2027-06-30', $entry->getNewExpiresAt()?->format('Y-m-d'));
    }

    #[Test]
    public function forExpiryAdjustmentAllowsNullableEnds(): void
    {
        // BAG-Verlängerung ohne neues Datum (faktisch unbegrenzt) ist gültig.
        $entry = LeaveEntitlementAuditEntry::forExpiryAdjustment(
            entitlement: $this->entitlement,
            actor: $this->admin,
            oldExpiresAt: new \DateTimeImmutable('2027-03-31'),
            newExpiresAt: null,
            occurredAt: new \DateTimeImmutable('2026-05-10'),
            reason: 'Frist aufgehoben',
        );

        self::assertNull($entry->getNewExpiresAt());
    }

    #[Test]
    public function forExpiryAdjustmentRejectsIdenticalDates(): void
    {
        $date = new \DateTimeImmutable('2027-03-31');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must record an actual change');

        LeaveEntitlementAuditEntry::forExpiryAdjustment(
            entitlement: $this->entitlement,
            actor: $this->admin,
            oldExpiresAt: $date,
            newExpiresAt: $date,
            occurredAt: new \DateTimeImmutable('2026-05-10'),
            reason: 'no-op',
        );
    }

    #[Test]
    public function forExpiryAdjustmentRejectsBothDatesNull(): void
    {
        // null → null ist kein Wechsel, also kein gültiger Audit-Anlass.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must record an actual change');

        LeaveEntitlementAuditEntry::forExpiryAdjustment(
            entitlement: $this->entitlement,
            actor: $this->admin,
            oldExpiresAt: null,
            newExpiresAt: null,
            occurredAt: new \DateTimeImmutable('2026-05-10'),
            reason: 'no-op',
        );
    }

    #[Test]
    public function actorMayBeNullForSystemActions(): void
    {
        $entry = LeaveEntitlementAuditEntry::forHoursAdjustment(
            entitlement: $this->entitlement,
            actor: null,
            oldHoursGranted: 240.0,
            newHoursGranted: 248.0,
            occurredAt: new \DateTimeImmutable('2026-05-10'),
            reason: 'Migration aus Altsystem',
        );

        self::assertNull($entry->getActor());
    }
}
