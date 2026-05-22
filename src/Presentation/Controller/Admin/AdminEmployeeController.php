<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Employee\EmployeeExitService;
use App\Domain\Calculator\ProRataEntitlementCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\ExitLeaveHandling;
use App\Domain\Enum\Weekday;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/employees', name: 'app_admin_employee_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEmployeeController extends AbstractController
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly EmployeeExitService $exitService,
        private readonly ProRataEntitlementCalculator $proRataCalculator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->currentCompany();
        $today = $this->clock->now();
        $filter = $request->query->getString('filter', 'active');
        if (!\in_array($filter, ['active', 'inactive', 'all'], true)) {
            $filter = 'active';
        }

        return $this->render('admin/employees/index.html.twig', [
            'employees' => $this->employeeRepository->findByCompanyAndStatus($company, $filter, $today),
            'filter' => $filter,
            'today' => $today,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->currentCompany();

        $form = $this->createForm(\App\Presentation\Form\EmployeeType::class, null, [
            'company' => $company,
            'is_edit' => false,
        ]);
        // Auto is the default for fresh creates — admins flip to manual
        // explicitly when they need an uneven distribution.
        $form->get('distributionMode')->setData(\App\Presentation\Form\EmployeeType::MODE_AUTO);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $employeeNumber = trim((string) $form->get('employeeNumber')->getData());

            if (null !== $this->employeeRepository->findOneByEmployeeNumber($company, $employeeNumber)) {
                $form->get('employeeNumber')->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.employees.error.duplicate_number', ['%number%' => $employeeNumber]),
                ));
            } else {
                try {
                    $employee = new Employee(
                        $company,
                        (string) $form->get('fullName')->getData(),
                        $employeeNumber,
                        $this->requireLocation($form),
                        $this->buildSchedule($form),
                        $this->requireDate($form, 'joinedAt'),
                        $this->optionalUser($form),
                        $this->optionalDate($form, 'leftAt'),
                    );

                    $employee->updateProbationEndsAt($this->optionalDate($form, 'probationEndsAt'));
                    $employee->assignToDepartment($this->optionalDepartment($form));

                    $this->entityManager->persist($employee);
                    $this->entityManager->flush();

                    $this->addFlash('success', $this->translator->trans(
                        'admin.employees.flash.created',
                        ['%name%' => $employee->getFullName()],
                    ));

                    return $this->redirectToRoute('app_admin_employee_index');
                } catch (\InvalidArgumentException $e) {
                    $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
                }
            }
        }

        return $this->render('admin/employees/form.html.twig', [
            'form' => $form,
            'is_new' => true,
            'proRataHint' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Employee $employee): Response
    {
        $company = $this->currentCompany();
        $this->assertSameCompany($employee, $company);

        $form = $this->createForm(\App\Presentation\Form\EmployeeType::class, null, [
            'company' => $company,
            'is_edit' => true,
            'current_employee' => $employee,
        ]);
        $this->prefillForm($form, $employee);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newNumber = trim((string) $form->get('employeeNumber')->getData());
            $collision = $this->employeeRepository->findOneByEmployeeNumber($company, $newNumber);

            if (null !== $collision && $collision !== $employee) {
                $form->get('employeeNumber')->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.employees.error.duplicate_number', ['%number%' => $newNumber]),
                ));
            } else {
                try {
                    $employee->rename((string) $form->get('fullName')->getData());
                    $employee->reassignLocation($this->requireLocation($form));
                    $employee->updateSchedule($this->buildSchedule($form));
                    $employee->updateJoinedAt($this->requireDate($form, 'joinedAt'));

                    $leftAt = $this->optionalDate($form, 'leftAt');
                    $exitSummary = null;
                    if (null === $employee->getLeftAt() && null !== $leftAt) {
                        $exitSummary = $this->exitService->execute($employee, $leftAt);
                    } elseif (null !== $leftAt) {
                        $employee->markLeft($leftAt);
                    }

                    $user = $this->optionalUser($form);
                    if (null === $user && $employee->hasUser()) {
                        $employee->unlinkUser();
                    } elseif (null !== $user && $user !== $employee->getUser()) {
                        $employee->linkUser($user);
                    }

                    $employee->updateProbationEndsAt($this->optionalDate($form, 'probationEndsAt'));
                    $employee->assignToDepartment($this->optionalDepartment($form));

                    $this->entityManager->flush();

                    if (null !== $exitSummary) {
                        $this->addFlash('success', $this->translator->trans(
                            'admin.employees.flash.exited',
                            ['%name%' => $employee->getFullName(), '%date%' => $exitSummary->exitDate->format('d.m.Y')],
                        ));
                        if ($exitSummary->hasRemainingBalance()) {
                            $handlingLabel = $this->translator->trans($exitSummary->exitLeaveHandling->translationKey());
                            $this->addFlash('warning', $this->translator->trans(
                                'admin.employees.flash.exit_remaining_balance',
                                [
                                    '%hours%' => number_format($exitSummary->totalRemainingHours, 2, ',', '.'),
                                    '%handling%' => $handlingLabel,
                                    '%handlingNote%' => $this->exitHandlingNote($exitSummary->exitLeaveHandling),
                                ],
                            ));
                        }
                    } else {
                        $this->addFlash('success', $this->translator->trans(
                            'admin.employees.flash.updated',
                            ['%name%' => $employee->getFullName()],
                        ));
                    }

                    return $this->redirectToRoute('app_admin_employee_index');
                } catch (\InvalidArgumentException $e) {
                    $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
                }
            }
        }

        return $this->render('admin/employees/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'employee' => $employee,
            'proRataHint' => $this->buildEmployeeProRataHint($employee),
        ]);
    }

    #[Route('/{id}/leave-requests', name: 'leave_requests', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function leaveRequests(Request $request, Employee $employee): Response
    {
        $this->assertSameCompany($employee, $this->currentCompany());

        $yearParam = $request->query->get('year');
        $year = \is_string($yearParam) && '' !== $yearParam && ctype_digit($yearParam)
            ? (int) $yearParam
            : null;

        $allRequests = $this->leaveRequestRepository->findAllByEmployee($employee, null);

        // Build year-filter dropdown options from the employee's actual
        // request history (start-year). De-duplicated, newest first.
        $availableYears = [];
        foreach ($allRequests as $req) {
            $availableYears[(int) $req->getStartDate()->format('Y')] = true;
        }
        $availableYears = array_keys($availableYears);
        rsort($availableYears);

        $filteredRequests = null === $year
            ? $allRequests
            : $this->leaveRequestRepository->findAllByEmployee($employee, $year);

        // Aggregate hours by year × absence type for the summary card.
        $yearAggregates = [];
        foreach ($allRequests as $req) {
            $reqYear = (int) $req->getStartDate()->format('Y');
            $typeName = $req->getAbsenceType()->getName();
            $yearAggregates[$reqYear][$typeName] = ($yearAggregates[$reqYear][$typeName] ?? 0.0) + $req->getTotalHours();
        }
        krsort($yearAggregates);

        return $this->render('admin/employees/leave_requests.html.twig', [
            'employee' => $employee,
            'requests' => $filteredRequests,
            'availableYears' => $availableYears,
            'selectedYear' => $year,
            'yearAggregates' => $yearAggregates,
        ]);
    }

    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('employee-export', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $company = $this->currentCompany();
        $today = $this->clock->now();

        $filter = $request->request->getString('filter', 'all');
        if (!\in_array($filter, ['active', 'inactive', 'all'], true)) {
            $filter = 'all';
        }

        $employees = $this->employeeRepository->findByCompanyAndStatus($company, $filter, $today);

        $filename = match ($filter) {
            'active' => 'mitarbeiter-aktive.csv',
            'inactive' => 'mitarbeiter-inaktive.csv',
            default => 'mitarbeiter-alle.csv',
        };

        return new Response(
            $this->buildCsv($employees, $today),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => \sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }

    /**
     * @param list<Employee> $employees
     */
    private function buildCsv(array $employees, \DateTimeImmutable $today): string
    {
        $day = $today->setTime(0, 0);

        $output = fopen('php://temp', 'r+');
        if (false === $output) {
            throw new \RuntimeException('Failed to open temp stream.');
        }

        $content = '';
        try {
            fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($output, [
                $this->translator->trans('admin.employees.export.csv.name'),
                $this->translator->trans('admin.employees.export.csv.number'),
                $this->translator->trans('admin.employees.export.csv.location'),
                $this->translator->trans('admin.employees.export.csv.status'),
                $this->translator->trans('admin.employees.export.csv.weekly_hours'),
                $this->translator->trans('admin.employees.export.csv.joined_at'),
                $this->translator->trans('admin.employees.export.csv.left_at'),
                $this->translator->trans('admin.employees.export.csv.login'),
            ], separator: ',', enclosure: '"', escape: '\\');

            foreach ($employees as $employee) {
                $isInactive = null !== $employee->getLeftAt() && $employee->getLeftAt() <= $day;
                fputcsv($output, array_map(
                    $this->sanitizeCsvCell(...),
                    [
                        $employee->getFullName(),
                        $employee->getEmployeeNumber(),
                        $employee->getLocation()->getName(),
                        $isInactive
                            ? $this->translator->trans('admin.employees.status.inactive')
                            : $this->translator->trans('admin.employees.status.active'),
                        (string) $employee->getWorkSchedule()->weeklyHours(),
                        $employee->getJoinedAt()->format('Y-m-d'),
                        $employee->getLeftAt()?->format('Y-m-d') ?? '',
                        $employee->getUser()?->getEmail() ?? '',
                    ],
                ), separator: ',', enclosure: '"', escape: '\\');
            }

            rewind($output);
            $content = (string) (stream_get_contents($output) ?: '');
        } finally {
            fclose($output);
        }

        return $content;
    }

    private function sanitizeCsvCell(string $value): string
    {
        if ('' !== $value && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    private function currentCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }

    private function assertSameCompany(Employee $employee, Company $company): void
    {
        if ($employee->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function requireLocation(FormInterface $form): Location
    {
        $location = $form->get('location')->getData();
        if (!$location instanceof Location) {
            throw new \InvalidArgumentException('A location is required.');
        }

        return $location;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function optionalUser(FormInterface $form): ?User
    {
        $user = $form->get('user')->getData();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function optionalDepartment(FormInterface $form): ?Department
    {
        $department = $form->get('department')->getData();

        return $department instanceof Department ? $department : null;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function requireDate(FormInterface $form, string $field): \DateTimeImmutable
    {
        $raw = $form->get($field)->getData();
        if (!$raw instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException(\sprintf('%s is required.', $field));
        }

        return \DateTimeImmutable::createFromInterface($raw);
    }

    private function exitHandlingNote(ExitLeaveHandling $handling): string
    {
        return match ($handling) {
            ExitLeaveHandling::PayOut => $this->translator->trans('admin.employees.exit_handling_note.pay_out'),
            ExitLeaveHandling::MandatoryConsumption => $this->translator->trans('admin.employees.exit_handling_note.mandatory_consumption'),
            ExitLeaveHandling::Freistellung => $this->translator->trans('admin.employees.exit_handling_note.freistellung'),
        };
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function optionalDate(FormInterface $form, string $field): ?\DateTimeImmutable
    {
        $raw = $form->get($field)->getData();
        if (!$raw instanceof \DateTimeInterface) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($raw);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function buildSchedule(FormInterface $form): WorkSchedule
    {
        $weeklyHours = (float) $form->get('weeklyHours')->getData();
        $mode = (string) $form->get('distributionMode')->getData();

        if (\App\Presentation\Form\EmployeeType::MODE_MANUAL === $mode) {
            $hoursPerDay = [];
            foreach (\App\Presentation\Form\EmployeeType::weekdayFieldMap() as [$fieldName, $weekday]) {
                $value = $form->get($fieldName)->getData();
                if (null === $value) {
                    continue;
                }
                $hours = (float) $value;
                if ($hours <= 0.0) {
                    continue;
                }
                $hoursPerDay[$weekday->value] = $hours;
            }

            return WorkSchedule::manual($weeklyHours, $hoursPerDay);
        }

        $dayValues = $form->get('workingDays')->getData();
        if (!\is_array($dayValues) || [] === $dayValues) {
            throw new \InvalidArgumentException('At least one working day is required.');
        }

        $days = [];
        foreach ($dayValues as $value) {
            $days[] = Weekday::from((int) $value);
        }

        return WorkSchedule::autoDistribute($weeklyHours, $days);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function prefillForm(FormInterface $form, Employee $employee): void
    {
        $schedule = $employee->getWorkSchedule();

        $form->get('fullName')->setData($employee->getFullName());
        $form->get('employeeNumber')->setData($employee->getEmployeeNumber());
        $form->get('location')->setData($employee->getLocation());
        $form->get('weeklyHours')->setData($schedule->weeklyHours());
        $form->get('workingDays')->setData(
            array_map(static fn (Weekday $d): int => $d->value, $schedule->workingDays()),
        );
        $form->get('joinedAt')->setData($employee->getJoinedAt());
        $form->get('probationEndsAt')->setData($employee->getProbationEndsAt());
        $form->get('leftAt')->setData($employee->getLeftAt());
        $form->get('user')->setData($employee->getUser());
        $form->get('department')->setData($employee->getDepartment());

        // Per-day fields always reflect the saved schedule so a switch to
        // manual mode in the UI starts from the current values.
        foreach (\App\Presentation\Form\EmployeeType::weekdayFieldMap() as [$fieldName, $weekday]) {
            $hours = $schedule->hoursForDay($weekday);
            $form->get($fieldName)->setData($hours > 0.0 ? $hours : null);
        }

        $form->get('distributionMode')->setData(
            $this->detectDistributionMode($schedule)
                ? \App\Presentation\Form\EmployeeType::MODE_AUTO
                : \App\Presentation\Form\EmployeeType::MODE_MANUAL,
        );
    }

    /**
     * Returns true if every working day has the same hours (within the
     * VO's epsilon tolerance) — the canonical "auto-distributed" shape.
     * Anything uneven gets surfaced as the manual mode so the admin sees
     * the per-day breakdown they're actually editing.
     */
    private function detectDistributionMode(WorkSchedule $schedule): bool
    {
        $days = $schedule->workingDays();
        if (\count($days) <= 1) {
            return true;
        }

        $reference = $schedule->hoursForDay($days[0]);
        foreach ($days as $day) {
            if (abs($schedule->hoursForDay($day) - $reference) > 0.01) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns pro-rata hint data for the employee edit form.
     *
     * Delegates month counting to ProRataEntitlementCalculator so both the
     * join-side and same-year exit-side are handled consistently and tested.
     * Probation is checked against actual elapsed days, not Zwölftel months.
     *
     * @return array{joinedAt: \DateTimeImmutable, effectiveMonths: int, year: int, probation: bool}|null
     */
    private function buildEmployeeProRataHint(Employee $employee): ?array
    {
        $joinedAt = $employee->getJoinedAt();
        $joinYear = (int) $joinedAt->format('Y');
        $leftAt = $employee->getLeftAt();

        $effectiveMonths = $this->proRataCalculator->effectiveMonthsForPeriod($joinedAt, $leftAt, $joinYear);

        if ($effectiveMonths >= 12 || 0 === $effectiveMonths) {
            return null;
        }

        $today = $this->clock->now()->setTime(0, 0);
        if ($joinYear < (int) $today->format('Y')) {
            return null;
        }
        $probation = $employee->isInProbation($today);

        return [
            'joinedAt' => $joinedAt,
            'effectiveMonths' => $effectiveMonths,
            'year' => $joinYear,
            'probation' => $probation,
        ];
    }
}
