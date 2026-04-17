<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\Weekday;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->currentCompany();

        return $this->render('admin/employees/index.html.twig', [
            'employees' => $this->employeeRepository->findAllByCompany($company),
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
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $employee = new Employee(
                    $company,
                    (string) $form->get('fullName')->getData(),
                    (string) $form->get('employeeNumber')->getData(),
                    $this->requireLocation($form),
                    $this->buildSchedule($form),
                    $this->requireDate($form, 'joinedAt'),
                    $this->optionalUser($form),
                    $this->optionalDate($form, 'leftAt'),
                );

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

        return $this->render('admin/employees/form.html.twig', [
            'form' => $form,
            'is_new' => true,
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
        ]);
        $this->prefillForm($form, $employee);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $employee->rename((string) $form->get('fullName')->getData());
                $employee->reassignLocation($this->requireLocation($form));
                $employee->updateSchedule($this->buildSchedule($form));

                $leftAt = $this->optionalDate($form, 'leftAt');
                if (null !== $leftAt) {
                    $employee->markLeft($leftAt);
                }

                $user = $this->optionalUser($form);
                if (null === $user && $employee->hasUser()) {
                    $employee->unlinkUser();
                } elseif (null !== $user && $user !== $employee->getUser()) {
                    $employee->linkUser($user);
                }

                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans(
                    'admin.employees.flash.updated',
                    ['%name%' => $employee->getFullName()],
                ));

                return $this->redirectToRoute('app_admin_employee_index');
            } catch (\InvalidArgumentException $e) {
                $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
            }
        }

        return $this->render('admin/employees/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'employee' => $employee,
        ]);
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
    private function requireDate(FormInterface $form, string $field): \DateTimeImmutable
    {
        $raw = $form->get($field)->getData();
        if (!$raw instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException(\sprintf('%s is required.', $field));
        }

        return \DateTimeImmutable::createFromInterface($raw);
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
        $form->get('fullName')->setData($employee->getFullName());
        $form->get('employeeNumber')->setData($employee->getEmployeeNumber());
        $form->get('location')->setData($employee->getLocation());
        $form->get('weeklyHours')->setData($employee->getWorkSchedule()->weeklyHours());
        $form->get('workingDays')->setData(
            array_map(static fn (Weekday $d): int => $d->value, $employee->getWorkSchedule()->workingDays()),
        );
        $form->get('joinedAt')->setData($employee->getJoinedAt());
        $form->get('leftAt')->setData($employee->getLeftAt());
        $form->get('user')->setData($employee->getUser());
    }
}
