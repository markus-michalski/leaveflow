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

namespace App\DataFixtures;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveEntitlementAuditEntry;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Entity\Location;
use App\Domain\Entity\Notification;
use App\Domain\Entity\ScheduledJobConfig;
use App\Domain\Entity\User;
use App\Domain\Enum\ExclusionReason;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\ScheduledJobRunStatus;
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
        // leftAt set to 2021-09-30 so the 36-month retention period (→ 2024-09-30) is
        // already elapsed: Hannah shows up in the DSGVO anonymization "fällig" list right
        // after `make fixtures`.
        $hannah = new Employee(
            $company,
            'Hannah History',
            'EMP-0003',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2019-05-01'),
            null,
            new \DateTimeImmutable('2021-09-30'),
        );
        $manager->persist($hannah);

        // DSGVO demo #2: second eligible-for-anonymization employee (left 2020-06-30,
        // retention elapsed 2023-06-30) — gives the anonymization page two rows in the
        // "fällig" list so the UI doesn't look like a one-off edge case.
        $klausKraft = new Employee(
            $company,
            'Klaus Kraft',
            'EMP-0005',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2015-03-01'),
            null,
            new \DateTimeImmutable('2020-06-30'),
        );
        $manager->persist($klausKraft);

        // DSGVO demo #3: already-anonymized employee — populates the "Bereits anonymisiert"
        // section on /admin/anonymization so both halves of the page render after fixtures.
        $preAnonymized = new Employee(
            $company,
            'Placeholder',
            'EMP-0006',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2013-07-01'),
            null,
            new \DateTimeImmutable('2018-12-31'),
        );
        $manager->persist($preAnonymized);
        $preAnonymized->anonymize('Ehemaliger Mitarbeiter (EMP-0006)', new \DateTimeImmutable('2022-03-15'));

        // Phase 10: two realistic departments so the statistics dashboard
        // shows non-empty department aggregates and the k-anonymity hide
        // logic is demonstrable side-by-side. Maya leads Engineering (4
        // members, visible); Operations has 3 members (visible). Hannah
        // is left orphaned to demonstrate the "Ohne Abteilung" bucket.
        $engineering = new Department($company, 'Engineering', lead: $maya);
        $operations = new Department($company, 'Operations');
        $manager->persist($engineering);
        $manager->persist($operations);
        $maya->assignToDepartment($engineering);
        $erik->assignToDepartment($operations);

        // Five additional employees so Engineering hits 4 active members
        // (well above k=3) and Operations hits exactly 3 (right at the
        // threshold). Mix full-time + part-time + late joiner for variety.
        $extraSeeds = [
            ['Lukas Lehmann', 'EMP-0010', $hq, WorkSchedule::standardFullTime(), '2024-06-01', $engineering],
            ['Sarah Sommer', 'EMP-0011', $hq, WorkSchedule::standardFullTime(), '2025-02-10', $engineering],
            ['David Diakos', 'EMP-0012', $hq, WorkSchedule::autoDistribute(32.0, [
                Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday,
            ]), '2025-08-01', $engineering],
            ['Pia Petersen', 'EMP-0013', $branchBerlin, WorkSchedule::standardFullTime(), '2024-11-15', $operations],
            ['Tom Tannenbaum', 'EMP-0014', $branchBerlin, WorkSchedule::autoDistribute(20.0, [
                Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday,
            ]), '2025-09-01', $operations],
        ];
        $extraEmployees = [];
        foreach ($extraSeeds as [$name, $number, $loc, $schedule, $joinedAt, $dept]) {
            $emp = new Employee(
                $company,
                $name,
                $number,
                $loc,
                $schedule,
                new \DateTimeImmutable($joinedAt),
            );
            $emp->assignToDepartment($dept);
            $manager->persist($emp);
            $extraEmployees[$number] = $emp;
        }

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

        // BlackoutPeriods: two demo entries so the admin page is never empty.
        // (1) Past company-wide freeze for year-end closing 2025.
        // (2) Upcoming Engineering release-freeze (dept-scoped) so admins see both
        //     scope variants side by side.
        $manager->persist(new BlackoutPeriod(
            $company,
            new \DateTimeImmutable('2025-12-22'),
            new \DateTimeImmutable('2025-12-23'),
            'Jahresabschluss — Systemwartung und Buchhaltung (gesamtes Unternehmen)',
        ));
        $manager->persist(new BlackoutPeriod(
            $company,
            (new \DateTimeImmutable())->setDate($currentYear, 7, 13)->setTime(0, 0),
            (new \DateTimeImmutable())->setDate($currentYear, 7, 18)->setTime(0, 0),
            'Release-Freeze Q3 — kein Urlaub während der Produktionsstabilisierung',
            $engineering,
        ));

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
        $erikCurrentYearEntitlement = null;
        foreach ([$currentYear - 1, $currentYear, $currentYear + 1] as $year) {
            $manager->persist(new LeaveEntitlement(
                $maya,
                $year,
                LeaveEntitlementType::Regular,
                240.0,
            ));
            // Erik works 30h/4d so 30 leave days map to 30 * 7.5h = 225h.
            $erikRegular = new LeaveEntitlement(
                $erik,
                $year,
                LeaveEntitlementType::Regular,
                225.0,
            );
            $manager->persist($erikRegular);
            if ($currentYear === $year) {
                $erikCurrentYearEntitlement = $erikRegular;
            }
        }
        \assert($erikCurrentYearEntitlement instanceof LeaveEntitlement);

        // Demo carryover for Maya: 16h still outstanding, expiring 31.03. of
        // the following year so the profile dashboard keeps showing an active
        // carryover regardless of when the fixtures are loaded. Admins can
        // edit the expiry via /admin/entitlements to simulate the abgelaufen-
        // case.
        $mayaCarryover = new LeaveEntitlement(
            $maya,
            $currentYear,
            LeaveEntitlementType::Carryover,
            16.0,
            (new \DateTimeImmutable())->setDate($currentYear + 1, 3, 31)->setTime(0, 0),
        );
        $manager->persist($mayaCarryover);

        // LeaveEntitlementAuditEntry: two historical admin edits on Maya's carryover
        // so the entitlement detail page renders a populated audit trail out-of-the-box.
        $now = new \DateTimeImmutable();
        $manager->persist(LeaveEntitlementAuditEntry::forHoursAdjustment(
            entitlement: $mayaCarryover,
            actor: null,
            oldHoursGranted: 8.0,
            newHoursGranted: 16.0,
            occurredAt: $now->modify('-45 days')->setTime(10, 30),
            reason: 'Korrektur: initiale Buchung war falsch (Tippfehler). Tatsächlicher Resturlaub 2025 beträgt 16 Stunden.',
        ));
        $manager->persist(LeaveEntitlementAuditEntry::forExpiryAdjustment(
            entitlement: $mayaCarryover,
            actor: null,
            oldExpiresAt: (new \DateTimeImmutable())->setDate($currentYear + 1, 3, 31)->setTime(0, 0),
            newExpiresAt: (new \DateTimeImmutable())->setDate($currentYear + 1, 6, 30)->setTime(0, 0),
            occurredAt: $now->modify('-20 days')->setTime(14, 15),
            reason: 'Verlängerung gem. BAG-Rechtsprechung: Mitarbeiterin war im Übertragungszeitraum krankgeschrieben (01.03.–28.03.). Verfall verschoben auf 30.06.',
        ));

        // Phase 5: demo leave requests so admins and employees see realistic
        // entries on their dashboards right after `make fixtures`.
        foreach ($this->leaveRequestSeeds($maya, $erik, $absenceTypesByName) as $request) {
            $manager->persist($request);
        }

        // Phase 9 demo: an Approved Urlaub request for Erik so the admin
        // type-change UX is screenshot-able right after `make fixtures` —
        // without an Approved request in the seed, the "Typ ändern" button
        // never surfaces. Entitlement is consumed to mirror real post-
        // approval state.
        $approvedRequest = $this->createApprovedDemoRequest(
            $erik,
            $absenceTypesByName['Urlaub'],
            $erikCurrentYearEntitlement,
        );
        $manager->persist($approvedRequest);

        // LeaveRequestAuditEntry: two entries for the approved request so the
        // /admin/leave-requests detail page renders a populated audit trail.
        // (1) Status transition: Maya approved the request 18 days ago.
        $manager->persist(new LeaveRequestAuditEntry(
            leaveRequest: $approvedRequest,
            actor: $maya,
            transition: 'approve',
            fromStatus: LeaveRequestStatus::Pending,
            toStatus: LeaveRequestStatus::Approved,
            occurredAt: $now->modify('-18 days')->setTime(11, 0),
            reason: null,
        ));
        // (2) Admin type-change: matches the AdminTypeChange notification already seeded
        //     in the inbox — the audit row is the paper trail for that reclassification.
        $manager->persist(LeaveRequestAuditEntry::forTypeChange(
            leaveRequest: $approvedRequest,
            actor: null,
            fromAbsenceType: $absenceTypesByName['Urlaub'],
            toAbsenceType: $absenceTypesByName['Sonderurlaub'],
            occurredAt: $now->modify('-90 minutes'),
            reason: 'Sonderurlaub gemäß BGB §616 — wurde versehentlich als Urlaub gebucht.',
        ));

        // Phase 10 demo: realistic distribution of approved vacation +
        // sick recordings + one pending request across the extra
        // employees, so the statistics dashboard shows non-empty cards
        // and bars right after `make fixtures`. Entitlements are sized
        // per employee schedule (~30 working days each).
        $this->seedStatisticsDemoData(
            $manager,
            $extraEmployees,
            $absenceTypesByName,
            $currentYear,
        );

        // Phase 10: populate the action-briefing buckets so admins land
        // on a realistic dashboard, not a "Alles im grünen Bereich"
        // banner. Two carryovers expiring within the 90-day horizon
        // (one red < 30d, one yellow ≥ 60d) plus a pending request
        // sitting > 14 days in the queue (red).
        $this->seedActionDemoData(
            $manager,
            $extraEmployees,
            $absenceTypesByName,
            $currentYear,
        );

        // Phase 10: populate the "Aktuell abwesend" card with a realistic
        // mix of approved leave + recorded illness covering the day the
        // fixtures load. Multiple employees with different end dates so
        // both the "endsToday" and the "until X" branches render.
        $this->seedCurrentAbsenceDemoData(
            $manager,
            array_merge(['EMP-0002' => $erik], $extraEmployees),
            $absenceTypesByName,
        );

        // Phase 8: pre-populated inbox so a fresh `make fixtures` lands on a
        // realistic notification screen — every per-type Twig partial gets
        // exercised, and a mix of read/unread shows both visual states.
        foreach ($this->notificationSeeds($users, $currentYear) as [$notification, $isRead]) {
            $manager->persist($notification);
            if ($isRead) {
                // Read timestamp 30 minutes after creation so the row reads
                // "received before, opened later" rather than "created-and-read
                // simultaneously" — the dashboard tooltip uses createdAt only,
                // so this is purely a state-correctness detail.
                $notification->markAsRead($notification->getCreatedAt()->modify('+30 minutes'));
            }
        }

        // ScheduledJobConfig: seed all five known jobs so /admin/scheduled-jobs
        // renders a realistic table instead of an empty page right after `make fixtures`.
        // exit-deactivation-check shows a failure so both the success and error
        // display branches are visible without any manual interaction.
        foreach ([
            ['year-transition',           true,  $now->setDate($currentYear, 1, 1)->setTime(2, 0),   ScheduledJobRunStatus::Success, null],
            ['entitlement-expiry-check',  true,  $now->modify('-1 day')->setTime(3, 10),              ScheduledJobRunStatus::Success, null],
            ['approval-escalation-check', true,  $now->modify('-4 hours'),                            ScheduledJobRunStatus::Success, null],
            ['illness-alert-check',       true,  $now->modify('-1 day')->setTime(3, 20),              ScheduledJobRunStatus::Success, null],
            ['exit-deactivation-check',   true,  $now->modify('-6 hours'),                            ScheduledJobRunStatus::Failure, 'Doctrine\DBAL\Exception\ConnectionException: An exception occurred in the driver: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
        ] as [$jobName, $enabled, $lastRunAt, $status, $error]) {
            $job = new ScheduledJobConfig($jobName, $enabled);
            $job->recordRun($lastRunAt, $status, $error);
            $manager->persist($job);
        }

        $manager->flush();
    }

    /**
     * Seeds approved vacation + sick recordings + one pending request
     * across the extra employees. The result populates the Phase 10
     * statistics dashboard with realistic non-zero KPIs and bars.
     *
     * @param array<string, Employee>     $employees           keyed by employeeNumber
     * @param array<string, AbsenceType>  $absenceTypesByName
     */
    private function seedStatisticsDemoData(
        ObjectManager $manager,
        array $employees,
        array $absenceTypesByName,
        int $currentYear,
    ): void {
        $urlaub = $absenceTypesByName['Urlaub'];
        $krankheit = $absenceTypesByName['Krankheit'];

        // Per-employee plan: vacation date ranges + sick date ranges. All
        // dates are weekday-only in the past half of $currentYear so they
        // contribute to the dashboard's "until today" range cap.
        $plans = [
            'EMP-0010' => [ // Lukas — heavy vacation user
                'grantDays' => 30,
                'vacation' => [
                    [\sprintf('%d-02-09', $currentYear), \sprintf('%d-02-13', $currentYear)],
                    [\sprintf('%d-04-13', $currentYear), \sprintf('%d-04-17', $currentYear)],
                ],
                'sick' => [
                    [\sprintf('%d-03-23', $currentYear), \sprintf('%d-03-25', $currentYear)],
                ],
            ],
            'EMP-0011' => [ // Sarah — moderate user
                'grantDays' => 30,
                'vacation' => [
                    [\sprintf('%d-03-02', $currentYear), \sprintf('%d-03-06', $currentYear)],
                ],
                'sick' => [
                    [\sprintf('%d-01-19', $currentYear), \sprintf('%d-01-23', $currentYear)],
                ],
            ],
            'EMP-0012' => [ // David — part-time, light user
                'grantDays' => 24,
                'vacation' => [
                    [\sprintf('%d-04-06', $currentYear), \sprintf('%d-04-09', $currentYear)],
                ],
                'sick' => [
                    [\sprintf('%d-02-17', $currentYear), \sprintf('%d-02-18', $currentYear)],
                ],
            ],
            'EMP-0013' => [ // Pia — moderate user, Operations
                'grantDays' => 30,
                'vacation' => [
                    [\sprintf('%d-01-12', $currentYear), \sprintf('%d-01-16', $currentYear)],
                    [\sprintf('%d-03-30', $currentYear), \sprintf('%d-04-02', $currentYear)],
                ],
                'sick' => [
                    [\sprintf('%d-04-27', $currentYear), \sprintf('%d-04-29', $currentYear)],
                ],
            ],
            'EMP-0014' => [ // Tom — part-time 3d/week, light user
                'grantDays' => 18,
                'vacation' => [
                    [\sprintf('%d-02-24', $currentYear), \sprintf('%d-02-26', $currentYear)],
                ],
                'sick' => [],
            ],
        ];

        foreach ($plans as $empNumber => $plan) {
            $emp = $employees[$empNumber];
            $schedule = $emp->getWorkSchedule();
            $workingDayCount = \count($schedule->workingDays());
            $hoursPerDay = $schedule->weeklyHours() / $workingDayCount;

            // Grant ≈ schedule × given working-day count, rounded to half hours
            // so the admin entitlement view stays tidy.
            $grantHours = round($hoursPerDay * $plan['grantDays'] * 2.0) / 2.0;
            $entitlement = new LeaveEntitlement(
                $emp,
                $currentYear,
                LeaveEntitlementType::Regular,
                $grantHours,
            );
            $manager->persist($entitlement);

            foreach ($plan['vacation'] as [$start, $end]) {
                $request = $this->buildDemoRequest(
                    $emp,
                    $urlaub,
                    new \DateTimeImmutable($start),
                    new \DateTimeImmutable($end),
                    $hoursPerDay,
                    LeaveRequestStatus::Approved,
                );
                if (null !== $request) {
                    $entitlement->consume($request->getTotalHours());
                    $manager->persist($request);
                }
            }

            foreach ($plan['sick'] as [$start, $end]) {
                $request = $this->buildDemoRequest(
                    $emp,
                    $krankheit,
                    new \DateTimeImmutable($start),
                    new \DateTimeImmutable($end),
                    $hoursPerDay,
                    LeaveRequestStatus::Recorded,
                );
                if (null !== $request) {
                    $manager->persist($request);
                }
            }
        }

        // One pending vacation request from Sarah so the "Offene Anträge"
        // KPI shows >1 right after `make fixtures` (Erik already has the
        // 2026-08 approved one + we add a pending June slot here).
        $sarah = $employees['EMP-0011'];
        $pendingStart = (new \DateTimeImmutable())->setDate($currentYear, 6, 15)->setTime(0, 0);
        $pendingEnd = (new \DateTimeImmutable())->setDate($currentYear, 6, 19)->setTime(0, 0);
        $sarahHoursPerDay = $sarah->getWorkSchedule()->weeklyHours()
            / \count($sarah->getWorkSchedule()->workingDays());
        $pending = $this->buildDemoRequest(
            $sarah,
            $urlaub,
            $pendingStart,
            $pendingEnd,
            $sarahHoursPerDay,
            LeaveRequestStatus::Pending,
            requestedAt: (new \DateTimeImmutable())->modify('-2 days')->setTime(9, 30),
        );
        if (null !== $pending) {
            $manager->persist($pending);
        }
    }

    /**
     * Creates a LeaveRequest with one LeaveDay per working day of the
     * employee's schedule between $start and $end (inclusive). Returns
     * null if the range contains no working days. Status is forced via
     * reflection to skip the workflow — fixtures only.
     *
     * `requestedAt` defaults to start@09:00; override when a Pending
     * demo needs to land in the dashboard's overdue bucket (must be
     * older than the threshold-days window).
     */
    private function buildDemoRequest(
        Employee $employee,
        AbsenceType $absenceType,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        float $hoursPerDay,
        LeaveRequestStatus $status,
        ?\DateTimeImmutable $requestedAt = null,
    ): ?LeaveRequest {
        $schedule = $employee->getWorkSchedule();
        $days = [];
        $workingDayCount = 0;
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $weekday = Weekday::fromDateTime($cursor);
            if ($schedule->isWorkingDay($weekday)) {
                $days[] = new LeaveDay($cursor, $hoursPerDay, LeaveDayStatus::Working);
                ++$workingDayCount;
                continue;
            }
            // Cover the full date range so LeaveRequest::applyBreakdown is happy:
            // Sa/So end up as Excluded(weekend), other off-days as NonWorkingDay
            // (matches part-time schedules like Tom's Tue/Wed/Thu pattern).
            $isWeekend = Weekday::Saturday === $weekday || Weekday::Sunday === $weekday;
            $days[] = new LeaveDay(
                $cursor,
                0.0,
                LeaveDayStatus::Excluded,
                $isWeekend ? ExclusionReason::Weekend : ExclusionReason::NonWorkingDay,
            );
        }

        if (0 === $workingDayCount) {
            return null;
        }

        $request = new LeaveRequest(
            $employee,
            $absenceType,
            $start,
            $end,
            LeaveDayType::FullDay,
            $requestedAt ?? $start->setTime(9, 0),
        );
        $request->applyBreakdown(new LeaveBreakdown($days));
        $request->setStatus($status);

        return $request;
    }

    /**
     * Seeds the action-briefing buckets on the statistics dashboard.
     *
     * Two carryovers chosen so admins see both tone variants right after
     * fixtures load: David's expires in 25 days (red, urgent) and Lukas's
     * in 66 days (yellow, on the radar). Both are admin-extended carryover
     * (BUrlG §7 Abs. 3 floor would have been year-03-31; the extension
     * pattern is realistic — illness-cause or BAG-recognized notification
     * gap). The expiry dates clamp upward against a "always in the
     * future"-floor so loads early in the year (before April) don't run
     * into the BUrlG floor exception.
     *
     * Pending request: Pia's late-May vacation request, requestedAt set
     * to 15 days ago — past the 5-day overdue threshold, far enough to
     * trigger the red tone (>14 days waiting). Demonstrates that the
     * dashboard can also surface admin-level intervention when the
     * manager hasn't acted.
     *
     * @param array<string, Employee>     $employees keyed by employeeNumber
     * @param array<string, AbsenceType>  $absenceTypesByName
     */
    private function seedActionDemoData(
        ObjectManager $manager,
        array $employees,
        array $absenceTypesByName,
        int $currentYear,
    ): void {
        $now = new \DateTimeImmutable();
        // Smallest legal expiry for a Year=$currentYear carryover is the
        // BUrlG floor (year-03-31). For a fixture that runs deterministically
        // any time of year, clamp upward.
        $burlgSafeFloor = (new \DateTimeImmutable())->setDate($currentYear, 4, 1)->setTime(0, 0);

        $expires25 = max($now->modify('+25 days')->setTime(0, 0), $burlgSafeFloor);
        $expires66 = max($now->modify('+66 days')->setTime(0, 0), $burlgSafeFloor->modify('+30 days'));

        $manager->persist(new LeaveEntitlement(
            $employees['EMP-0012'], // David — part-time, 32h/week
            $currentYear,
            LeaveEntitlementType::Carryover,
            16.0,
            $expires25,
        ));
        $manager->persist(new LeaveEntitlement(
            $employees['EMP-0010'], // Lukas — full-time
            $currentYear,
            LeaveEntitlementType::Carryover,
            24.0,
            $expires66,
        ));

        // Overdue pending request from Pia. requestedAt is 15 days ago so
        // the dashboard's red-tone branch (> 14 days waiting) renders.
        // Range pinned to Mon-Fri so the LeaveBreakdown coverage check
        // doesn't trip on weekends inside the requested span.
        $pia = $employees['EMP-0013'];
        $piaHoursPerDay = $pia->getWorkSchedule()->weeklyHours()
            / \count($pia->getWorkSchedule()->workingDays());
        $cursor = $now->modify('+45 days');
        while ('1' !== $cursor->format('N')) {
            $cursor = $cursor->modify('+1 day');
        }
        $overdueStart = $cursor->setTime(0, 0);
        $overdueEnd = $overdueStart->modify('+4 days');
        $overdue = $this->buildDemoRequest(
            $pia,
            $absenceTypesByName['Urlaub'],
            $overdueStart,
            $overdueEnd,
            $piaHoursPerDay,
            LeaveRequestStatus::Pending,
            requestedAt: $now->modify('-15 days')->setTime(9, 0),
        );
        if (null !== $overdue) {
            $manager->persist($overdue);
        }
    }

    /**
     * Seeds the "Aktuell abwesend" card on the dashboard. Five overlapping
     * absences spread around `now` so admins immediately see what the
     * widget looks like with mixed states: one ending today, one in the
     * middle of a longer leave, one fresh sick recording.
     *
     * Each absence picks the next available Mon-anchored week so the
     * range never crosses a weekend in a way buildDemoRequest can't
     * cover (the helper handles weekends as Excluded days now, but
     * Mon-Fri ranges keep the breakdown small and the demo data
     * predictable across DST / year boundaries).
     *
     * @param array<string, Employee>     $employees keyed by employeeNumber
     * @param array<string, AbsenceType>  $absenceTypesByName
     */
    private function seedCurrentAbsenceDemoData(
        ObjectManager $manager,
        array $employees,
        array $absenceTypesByName,
    ): void {
        $now = (new \DateTimeImmutable())->setTime(0, 0);
        $urlaub = $absenceTypesByName['Urlaub'];
        $krankheit = $absenceTypesByName['Krankheit'];

        // Walk to the Monday of the current week so the seven-day spans
        // line up with weekends predictably regardless of which day the
        // fixtures load on.
        $monday = $now;
        while ('1' !== $monday->format('N')) {
            $monday = $monday->modify('-1 day');
        }

        // Anchor every range to $monday with enough padding either side
        // that "today" stays inside the range no matter which weekday
        // the fixtures load on. Without this, weekend loads silently
        // drop the demo entries from the dashboard's "aktiv heute" view.
        $plans = [
            // Erik — long approved vacation, last week through next week.
            ['EMP-0002', $urlaub, $monday->modify('-14 days'), $monday->modify('+11 days'), LeaveRequestStatus::Approved],
            // Pia — approved vacation, this Monday through next Friday.
            ['EMP-0013', $urlaub, $monday, $monday->modify('+11 days'), LeaveRequestStatus::Approved],
            // Tom — recorded illness across last + this week (Tom only works Tue-Thu, so the wide range still produces working days).
            ['EMP-0014', $krankheit, $monday->modify('-7 days'), $monday->modify('+6 days'), LeaveRequestStatus::Recorded],
            // Lukas — recorded illness this week, including the weekend.
            ['EMP-0010', $krankheit, $monday, $monday->modify('+6 days'), LeaveRequestStatus::Recorded],
            // David — approved vacation that ends today (drives the endsToday=true branch). Range starts the Saturday of the previous week.
            ['EMP-0012', $urlaub, $monday->modify('-2 days'), $now, LeaveRequestStatus::Approved],
        ];

        foreach ($plans as [$empKey, $type, $start, $end, $status]) {
            if (!isset($employees[$empKey])) {
                continue;
            }
            $employee = $employees[$empKey];
            $schedule = $employee->getWorkSchedule();
            $hoursPerDay = $schedule->weeklyHours() / \count($schedule->workingDays());
            $request = $this->buildDemoRequest(
                $employee,
                $type,
                $start,
                $end,
                $hoursPerDay,
                $status,
                requestedAt: $start->modify('-2 days')->setTime(9, 0),
            );
            if (null !== $request) {
                $manager->persist($request);
            }
        }
    }

    /**
     * Creates a 3-day Approved Urlaub request for Erik with the matching hours
     * already consumed from his current-year entitlement. Status is forced
     * via reflection — Phase 6's workflow handles real approvals; this is a
     * fixture shortcut, not application logic.
     */
    private function createApprovedDemoRequest(
        Employee $erik,
        AbsenceType $urlaub,
        LeaveEntitlement $erikRegular,
    ): LeaveRequest {
        // Erik works Mon-Thu 30h/week → 7.5h/day. Pick three weekdays clear of
        // German holidays: Mo-Mi, 2026-08-10..12. Total 22.5h.
        $hoursPerDay = 7.5;
        $workingDays = [
            new \DateTimeImmutable('2026-08-10'),
            new \DateTimeImmutable('2026-08-11'),
            new \DateTimeImmutable('2026-08-12'),
        ];

        $request = new LeaveRequest(
            $erik,
            $urlaub,
            $workingDays[0],
            end($workingDays),
            LeaveDayType::FullDay,
            new \DateTimeImmutable('2026-05-02 14:30:00'),
        );

        $days = array_map(
            static fn (\DateTimeImmutable $d): LeaveDay => new LeaveDay($d, $hoursPerDay, LeaveDayStatus::Working),
            $workingDays,
        );
        $request->applyBreakdown(new LeaveBreakdown($days));

        // Mirror the post-approval state: entitlement consumed, request
        // status flipped. Real workflow is in Phase 6's ApprovalWorkflow.
        $erikRegular->consume($hoursPerDay * \count($workingDays));
        (new \ReflectionProperty(LeaveRequest::class, 'status'))
            ->setValue($request, LeaveRequestStatus::Approved);

        return $request;
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
        // Urlaub draws from the current year's Regular entitlement only —
        // the Resturlaub (Carryover) bucket is reserved for the previous
        // year's leftover with its own statutory expiry window.
        yield new AbsenceType($company, 'Urlaub', true, true, '#3B82F6', true, LeaveEntitlementType::Regular);
        yield new AbsenceType($company, 'Resturlaub', true, true, '#6366F1', true, LeaveEntitlementType::Carryover);
        // Krankheit: eAU since 2023 means no upload, no approval gate, no
        // deduction. illnessTracking=true feeds the 6-week-illness alarm.
        yield new AbsenceType(
            $company,
            'Krankheit',
            false,
            false,
            '#EF4444',
            illnessTracking: true,
        );
        // Überstundenabbau draws from an overtime balance we don't model yet.
        // Until that bank exists, we don't deduct from the regular leave balance —
        // otherwise taking TOIL would wrongly eat vacation days.
        yield new AbsenceType($company, 'Überstundenabbau', false, true, '#10B981');
        // Sonderurlaub per BGB §616: additional paid leave, does NOT deduct from
        // the regular entitlement. Manager approves because the reason (bereavement,
        // own wedding, birth, etc.) has to be verified.
        yield new AbsenceType($company, 'Sonderurlaub', false, true, '#F59E0B');
        yield new AbsenceType($company, 'Fortbildung', false, true, '#8B5CF6');
        // No deduction, no approval — gets Recorded status immediately, appears in
        // team calendar via the Approved|Recorded filter.
        yield new AbsenceType($company, 'Berufsschule', false, false, '#06B6D4');
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

    /**
     * Demo notifications spread across all 7 NotificationType cases, mixing
     * read + unread for each recipient role. Timestamps are anchored to "now"
     * so the inbox always feels current regardless of when fixtures load.
     *
     * @param array<string, User> $users
     *
     * @return iterable<array{0: Notification, 1: bool}> tuple of [notification, isRead]
     */
    private function notificationSeeds(array $users, int $currentYear): iterable
    {
        $admin = $users['admin@leaveflow.test'];
        $manager = $users['manager@leaveflow.test'];
        $employee = $users['employee@leaveflow.test'];

        $now = new \DateTimeImmutable();

        // Manager (Maya) — receives team-incoming signals.
        yield [new Notification(
            recipient: $manager,
            type: NotificationType::ApprovalRequested,
            payload: [
                'employeeName' => 'Erik Employee',
                'absenceTypeName' => 'Urlaub',
                'startDate' => '06.07.2026',
                'endDate' => '10.07.2026',
            ],
            createdAt: $now->modify('-2 hours'),
        ), false];

        yield [new Notification(
            recipient: $manager,
            type: NotificationType::CancelRequested,
            payload: [
                'employeeName' => 'Erik Employee',
                'absenceTypeName' => 'Fortbildung',
                'startDate' => '15.06.2026',
                'endDate' => '17.06.2026',
            ],
            createdAt: $now->modify('-1 day'),
        ), false];

        yield [new Notification(
            recipient: $manager,
            type: NotificationType::RequestWithdrawn,
            payload: [
                'employeeName' => 'Erik Employee',
                'absenceTypeName' => 'Sonderurlaub',
                'startDate' => '02.05.2026',
                'endDate' => '02.05.2026',
            ],
            createdAt: $now->modify('-3 days'),
        ), true];

        // Employee (Erik) — receives decisions about own requests + entitlement
        // expiry warnings.
        yield [new Notification(
            recipient: $employee,
            type: NotificationType::ApprovalDecided,
            payload: [
                'decision' => 'approved',
                'approverName' => 'Maya Manager',
                'absenceTypeName' => 'Urlaub',
                'startDate' => '06.07.2026',
                'endDate' => '10.07.2026',
                'reason' => '',
            ],
            createdAt: $now->modify('-30 minutes'),
        ), false];

        yield [new Notification(
            recipient: $employee,
            type: NotificationType::ApprovalDecided,
            payload: [
                'decision' => 'rejected',
                'approverName' => 'Maya Manager',
                'absenceTypeName' => 'Fortbildung',
                'startDate' => '15.06.2026',
                'endDate' => '17.06.2026',
                'reason' => 'Termin überschneidet sich mit Q3-Release.',
            ],
            createdAt: $now->modify('-5 days'),
        ), true];

        yield [new Notification(
            recipient: $employee,
            type: NotificationType::CancelDecided,
            payload: [
                'decision' => 'confirmed',
                'approverName' => 'Maya Manager',
                'absenceTypeName' => 'Sonderurlaub',
                'startDate' => '02.05.2026',
                'endDate' => '02.05.2026',
                'reason' => '',
            ],
            createdAt: $now->modify('-7 days'),
        ), true];

        yield [new Notification(
            recipient: $employee,
            type: NotificationType::EntitlementExpiringSoon,
            payload: [
                'hoursRemaining' => 30.0,
                'expiresAt' => \sprintf('31.03.%d', $currentYear + 1),
                'daysRemaining' => 14,
            ],
            createdAt: $now->modify('-4 hours'),
        ), false];

        // Phase 9 demo: admin reclassified an old request — Erik's
        // bereavement leave was wrongly logged as Urlaub.
        yield [new Notification(
            recipient: $employee,
            type: NotificationType::AdminTypeChange,
            payload: [
                'oldTypeName' => 'Urlaub',
                'newTypeName' => 'Sonderurlaub',
                'startDate' => '02.05.2026',
                'endDate' => '02.05.2026',
                'adminName' => 'Anna Admin',
                'reason' => 'Sonderurlaub gemäß BGB §616 — wurde versehentlich als Urlaub gebucht.',
            ],
            createdAt: $now->modify('-90 minutes'),
        ), false];

        // Admin — receives the escalation backstop.
        yield [new Notification(
            recipient: $admin,
            type: NotificationType::EscalationTriggered,
            payload: [
                'employeeName' => 'Erik Employee',
                'absenceTypeName' => 'Urlaub',
                'startDate' => '20.04.2026',
                'endDate' => '24.04.2026',
                'daysWaiting' => 5,
            ],
            createdAt: $now->modify('-6 hours'),
        ), false];
    }
}
