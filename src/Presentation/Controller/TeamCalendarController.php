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

namespace App\Presentation\Controller;

use App\Domain\Calculator\HolidayCalculator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\User;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Repository\AbsenceTypeRepository;
use App\Domain\Repository\BlackoutPeriodRepository;
use App\Domain\Repository\CompanyHolidayRepository;
use App\Domain\Repository\DepartmentRepository;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\ValueObject\HolidayScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Team Calendar (Phase 7) — read-only calendar of approved leave requests for
 * the current user's company.
 *
 * - Default scope: current user's department (admins see the whole company).
 * - JSON feed exposes events in FullCalendar format for the Stimulus
 *   controller. The HTML page is just the FullCalendar shell.
 */
#[Route('/team/calendar', name: 'app_team_calendar_')]
#[IsGranted('ROLE_USER')]
final class TeamCalendarController extends AbstractController
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly DepartmentRepository $departmentRepository,
        private readonly AbsenceTypeRepository $absenceTypeRepository,
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly BlackoutPeriodRepository $blackoutRepository,
        private readonly HolidayCalculator $holidayCalculator,
        private readonly CompanyHolidayRepository $companyHolidayRepository,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUserOrThrow();
        $company = $user->getCompany();
        $employee = $this->employeeRepository->findOneBy(['user' => $user]);

        $defaultDepartmentId = $this->resolveDefaultDepartmentId($employee);

        return $this->render('team/calendar/index.html.twig', [
            'departments' => $this->departmentRepository->findBy(
                ['company' => $company],
                ['name' => 'ASC'],
            ),
            'absenceTypes' => $this->absenceTypeRepository->findBy(
                ['company' => $company],
                ['name' => 'ASC'],
            ),
            'defaultDepartmentId' => $defaultDepartmentId,
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
            'holidays' => $this->buildHolidaysForCalendar($company),
        ]);
    }

    #[Route('/events.json', name: 'events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        $user = $this->getUserOrThrow();
        $company = $user->getCompany();
        $employee = $this->employeeRepository->findOneBy(['user' => $user]);

        $start = $this->parseDateOrDefault((string) $request->query->get('start'), '-1 month');
        $end = $this->parseDateOrDefault((string) $request->query->get('end'), '+2 months');

        $department = $this->resolveDepartmentFilter($request, $user, $employee);
        $absenceType = $this->resolveAbsenceTypeFilter($request, $company);

        $leaves = $this->leaveRequestRepository->findActiveOverlapping(
            $company,
            $start,
            $end,
            $department,
            $absenceType,
        );

        $events = [];
        foreach ($leaves as $leave) {
            $endExclusive = $leave->getEndDate()->modify('+1 day');
            $events[] = [
                'id' => 'leave-'.$leave->getId(),
                'title' => $this->buildLeaveTitle($leave->getEmployee(), $leave),
                'start' => $leave->getStartDate()->format('Y-m-d'),
                'end' => $endExclusive->format('Y-m-d'),
                'allDay' => true,
                'backgroundColor' => $leave->getAbsenceType()->getColor(),
                'borderColor' => $leave->getAbsenceType()->getColor(),
                'extendedProps' => [
                    'kind' => 'leave',
                    'employee' => $leave->getEmployee()->getFullName(),
                    'absenceType' => $leave->getAbsenceType()->getName(),
                    'dayType' => $leave->getDayType()->value,
                ],
            ];
        }

        // Blackout periods rendered as background events (red overlay).
        $blackouts = $this->blackoutRepository->findOverlapping($company, $start, $end, $department);
        foreach ($blackouts as $blackout) {
            $endExclusive = $blackout->getEndDate()->modify('+1 day');
            $events[] = [
                'id' => 'blackout-'.$blackout->getId(),
                'title' => $blackout->getReason(),
                'start' => $blackout->getStartDate()->format('Y-m-d'),
                'end' => $endExclusive->format('Y-m-d'),
                'allDay' => true,
                'display' => 'background',
                'backgroundColor' => '#FCA5A5',
                'extendedProps' => [
                    'kind' => 'blackout',
                    'reason' => $blackout->getReason(),
                ],
            ];
        }

        return new JsonResponse($events);
    }

    private function getUserOrThrow(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function resolveDefaultDepartmentId(?Employee $employee): ?int
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return null;
        }

        return $employee?->getDepartment()?->getId();
    }

    private function resolveDepartmentFilter(Request $request, User $user, ?Employee $employee): ?Department
    {
        $teamId = $request->query->get('team');
        if ('' !== $teamId && null !== $teamId) {
            $department = $this->departmentRepository->find((int) $teamId);
            if ($department instanceof Department && $department->getCompany() === $user->getCompany()) {
                return $department;
            }
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            return null;
        }

        return $employee?->getDepartment();
    }

    private function resolveAbsenceTypeFilter(Request $request, Company $company): ?AbsenceType
    {
        $typeId = $request->query->get('type');
        if ('' === $typeId || null === $typeId) {
            return null;
        }

        $type = $this->absenceTypeRepository->find((int) $typeId);
        if ($type instanceof AbsenceType && $type->getCompany() === $company) {
            return $type;
        }

        return null;
    }

    private function parseDateOrDefault(string $raw, string $fallbackOffset): \DateTimeImmutable
    {
        if ('' !== $raw) {
            try {
                return (new \DateTimeImmutable($raw))->setTime(0, 0);
            } catch (\Exception) {
                // Fall through to default.
            }
        }

        return (new \DateTimeImmutable($fallbackOffset))->setTime(0, 0);
    }

    private function buildLeaveTitle(Employee $employee, \App\Domain\Entity\LeaveRequest $leave): string
    {
        $base = $employee->getFullName().' — '.$leave->getAbsenceType()->getName();

        return LeaveDayType::FullDay !== $leave->getDayType()
            ? $base.' (½)'
            : $base;
    }

    /**
     * Builds a flat list of {date, name} entries covering currentYear ± 1.
     * Includes national public holidays (state-independent) and company-specific
     * holidays stored in the DB. Regional holidays are omitted intentionally —
     * the team calendar has no single federal state context.
     *
     * @return list<array{date: string, name: string}>
     */
    private function buildHolidaysForCalendar(Company $company): array
    {
        $currentYear = (int) $this->clock->now()->format('Y');
        $years = [$currentYear - 1, $currentYear, $currentYear + 1];
        $seen = [];
        $result = [];

        foreach ($years as $year) {
            // Any state works here — national holidays are state-independent.
            $holidays = $this->holidayCalculator->calculate($year, FederalState::NordrheinWestfalen);
            foreach ($holidays as $holiday) {
                if (HolidayScope::National !== $holiday->scope) {
                    continue;
                }
                $key = $holiday->date->format('Y-m-d');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $result[] = ['date' => $key, 'name' => $this->translator->trans($holiday->nameKey)];
            }
        }

        foreach ($years as $year) {
            foreach ($this->companyHolidayRepository->findByCompanyAndYear($company, $year) as $ch) {
                $key = $ch->getDate()->format('Y-m-d');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $result[] = ['date' => $key, 'name' => $ch->getName()];
            }
        }

        return $result;
    }
}
