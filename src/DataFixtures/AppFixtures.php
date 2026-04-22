<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public const DEFAULT_PASSWORD = 'leaveflow-dev';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $company = new Company('Acme GmbH', 36);
        $manager->persist($company);

        $hq = new Location($company, 'Hauptsitz München', 'DE', 'DE-BY', 'München');
        $branchBerlin = new Location($company, 'Standort Berlin', 'DE', 'DE-BE', 'Berlin');
        $manager->persist($hq);
        $manager->persist($branchBerlin);

        $users = [];
        foreach ($this->userSeeds() as [$email, $role]) {
            $user = new User($company, $email, $role);
            $user->setHashedPassword(
                $this->passwordHasher->hashPassword($user, self::DEFAULT_PASSWORD)
            );
            $manager->persist($user);
            $users[$email] = $user;
        }

        // Admin stays user-only (external IT account — no HR record).
        // Manager + Employee get full employee profiles.
        $maya = new Employee(
            $company,
            'Maya Manager',
            'EMP-0001',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-15'),
            $users['manager@leaveflow.test'],
        );
        $manager->persist($maya);

        $erik = new Employee(
            $company,
            'Erik Employee',
            'EMP-0002',
            $branchBerlin,
            WorkSchedule::autoDistribute(30.0, [
                Weekday::Monday,
                Weekday::Tuesday,
                Weekday::Wednesday,
                Weekday::Thursday,
            ]),
            new \DateTimeImmutable('2025-03-01'),
            $users['employee@leaveflow.test'],
        );
        $manager->persist($erik);

        // Demonstrates Employee without User (pre-go-live import / archived ex-employee).
        $hannah = new Employee(
            $company,
            'Hannah History',
            'EMP-0003',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2019-05-01'),
            null,
            new \DateTimeImmutable('2024-09-30'),
        );
        $manager->persist($hannah);

        // Phase 6: single default "Alle" department per company. Maya
        // (Manager role) is the lead — Erik submits, Maya approves. No
        // deputy in the demo seed so the Admin fallback path is visible
        // when Maya herself submits a request.
        $alle = new Department($company, 'Alle', lead: $maya);
        $manager->persist($alle);
        $maya->assignToDepartment($alle);
        $erik->assignToDepartment($alle);
        $hannah->assignToDepartment($alle);

        // Phase 3: demo holiday configuration for the current + next year.
        // No state-wide override demo on purpose — Augsburger Friedensfest (8.8.)
        // is city-level only (Art. 1 Abs. 1 Nr. 4b BayFTG, Augsburg only), so
        // demoing it as a DE-BY override would mislead users. Municipality-level
        // holidays will land with location-scoped overrides in a later phase.
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');
        foreach ([$currentYear, $currentYear + 1] as $year) {
            // Brueckentag after Tag der Deutschen Einheit (Friday if 3.10. is Thursday; pragmatic: skip if weekend).
            $tdde = (new \DateTimeImmutable())->setDate($year, 10, 3)->setTime(0, 0);
            if ('Thursday' === $tdde->format('l')) {
                $manager->persist(new CompanyHoliday(
                    $company,
                    $tdde->modify('+1 day'),
                    'Brückentag nach Tag der Deutschen Einheit',
                ));
            }

            // Company-wide Betriebsruhe between Christmas and New Year (Dec 27-31).
            for ($day = 27; $day <= 31; ++$day) {
                $date = (new \DateTimeImmutable())->setDate($year, 12, $day)->setTime(0, 0);
                if ('Saturday' === $date->format('l') || 'Sunday' === $date->format('l')) {
                    continue;
                }
                $manager->persist(new CompanyHoliday(
                    $company,
                    $date,
                    'Betriebsruhe Weihnachten',
                ));
            }
        }

        // Phase 4: default AbsenceTypes (six entries mirroring the roadmap).
        /** @var array<string, AbsenceType> $absenceTypesByName */
        $absenceTypesByName = [];
        foreach ($this->absenceTypeSeeds($company) as $absenceType) {
            $manager->persist($absenceType);
            $absenceTypesByName[$absenceType->getName()] = $absenceType;
        }

        // Phase 5: three years of Regular entitlements (previous, current, next)
        // so manual tests can exercise year-boundary requests and both the
        // backdated-guard (previous year) and the "no entitlement" edge never
        // fires just because the seed doesn't cover the range.
        foreach ([$currentYear - 1, $currentYear, $currentYear + 1] as $year) {
            $manager->persist(new LeaveEntitlement(
                $maya,
                $year,
                LeaveEntitlementType::Regular,
                240.0,
            ));
            // Erik works 30h/4d so 30 leave days map to 30 * 7.5h = 225h.
            $manager->persist(new LeaveEntitlement(
                $erik,
                $year,
                LeaveEntitlementType::Regular,
                225.0,
            ));
        }

        // Demo carryover for Maya: 16h still outstanding, expiring 31.03. of
        // the following year so the profile dashboard keeps showing an active
        // carryover regardless of when the fixtures are loaded. Admins can
        // edit the expiry via /admin/entitlements to simulate the abgelaufen-
        // case.
        $manager->persist(new LeaveEntitlement(
            $maya,
            $currentYear,
            LeaveEntitlementType::Carryover,
            16.0,
            (new \DateTimeImmutable())->setDate($currentYear + 1, 3, 31)->setTime(0, 0),
        ));

        // Phase 5: demo leave requests so admins and employees see realistic
        // entries on their dashboards right after `make fixtures`.
        foreach ($this->leaveRequestSeeds($maya, $erik, $absenceTypesByName) as $request) {
            $manager->persist($request);
        }

        $manager->flush();
    }

    /**
     * @param array<string, AbsenceType> $absenceTypesByName
     *
     * @return iterable<LeaveRequest>
     */
    private function leaveRequestSeeds(
        Employee $maya,
        Employee $erik,
        array $absenceTypesByName,
    ): iterable {
        // Maya: 5 days of summer vacation in July (full-time Mon-Fri, 40h).
        $summer = new LeaveRequest(
            $maya,
            $absenceTypesByName['Urlaub'],
            new \DateTimeImmutable('2026-07-06'),
            new \DateTimeImmutable('2026-07-10'),
            LeaveDayType::FullDay,
            new \DateTimeImmutable('2026-04-15 10:00:00'),
        );
        $summer->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-07-06'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-07'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-08'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-09'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-10'), 8.0, LeaveDayStatus::Working),
        ]));
        yield $summer;

        // Erik: single half-day sick leave (non-deducting). Erik's schedule is
        // Mon-Thu 7.5h each — 22.04.2026 is a Wednesday, so the half-day is 3.75h.
        $sick = new LeaveRequest(
            $erik,
            $absenceTypesByName['Krankheit'],
            new \DateTimeImmutable('2026-04-22'),
            new \DateTimeImmutable('2026-04-22'),
            LeaveDayType::HalfDayAm,
            new \DateTimeImmutable('2026-04-22 08:15:00'),
        );
        $sick->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-04-22'), 3.75, LeaveDayStatus::HalfDay),
        ]));
        yield $sick;
    }

    /**
     * @return iterable<AbsenceType>
     */
    private function absenceTypeSeeds(Company $company): iterable
    {
        yield new AbsenceType($company, 'Urlaub', true, true, '#3B82F6');
        yield new AbsenceType($company, 'Resturlaub', true, true, '#6366F1');
        // Krankheit: eAU since 2023 means no upload, no approval gate, no deduction.
        yield new AbsenceType($company, 'Krankheit', false, false, '#EF4444');
        // Überstundenabbau draws from an overtime balance we don't model yet.
        // Until that bank exists, we don't deduct from the regular leave balance —
        // otherwise taking TOIL would wrongly eat vacation days.
        yield new AbsenceType($company, 'Überstundenabbau', false, true, '#10B981');
        // Sonderurlaub per BGB §616: additional paid leave, does NOT deduct from
        // the regular entitlement. Manager approves because the reason (bereavement,
        // own wedding, birth, etc.) has to be verified.
        yield new AbsenceType($company, 'Sonderurlaub', false, true, '#F59E0B');
        yield new AbsenceType($company, 'Fortbildung', false, true, '#8B5CF6');
    }

    /**
     * @return iterable<array{0: string, 1: UserRole}>
     */
    private function userSeeds(): iterable
    {
        yield ['admin@leaveflow.test', UserRole::Admin];
        yield ['manager@leaveflow.test', UserRole::Manager];
        yield ['employee@leaveflow.test', UserRole::Employee];
    }
}
