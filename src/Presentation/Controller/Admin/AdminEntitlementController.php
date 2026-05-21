<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Calculator\ProRataEntitlementCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveEntitlementAuditEntry;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LeaveEntitlementAuditEntryRepository;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Presentation\Form\LeaveEntitlementExpiresAtFormType;
use App\Presentation\Form\LeaveEntitlementFormType;
use App\Presentation\Form\LeaveEntitlementGrantedHoursFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/entitlements', name: 'app_admin_entitlement_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEntitlementController extends AbstractController
{
    public function __construct(
        private readonly LeaveEntitlementRepository $repository,
        private readonly CompanyRepository $companyRepository,
        private readonly EmployeeRepository $employeeRepository,
        private readonly LeaveEntitlementAuditEntryRepository $auditRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ProRataEntitlementCalculator $proRataCalculator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->requireCompany();

        // Default to the current year so admins of long-running tenants
        // don't have to scroll past years of history every visit. Explicit
        // `?year=all` shows everything; `?year=2026` shows just that year.
        $availableYears = $this->repository->findAvailableYears($company);
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');

        $yearParam = $request->query->get('year');
        $selectedYear = match (true) {
            'all' === $yearParam => null,
            \is_string($yearParam) && '' !== $yearParam && ctype_digit($yearParam) => (int) $yearParam,
            default => $currentYear,
        };

        return $this->render('admin/entitlements/index.html.twig', [
            'entries' => $this->repository->findByCompanyAndYear($company, $selectedYear),
            'today' => new \DateTimeImmutable('today'),
            'availableYears' => $availableYears,
            'selectedYear' => $selectedYear,
            'currentYear' => $currentYear,
        ]);
    }

    #[Route('/export.csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $company = $this->requireCompany();

        $yearParam = $request->query->get('year');
        $selectedYear = match (true) {
            'all' === $yearParam => null,
            \is_string($yearParam) && '' !== $yearParam && ctype_digit($yearParam) => (int) $yearParam,
            default => (int) (new \DateTimeImmutable())->format('Y'),
        };

        $entries = $this->repository->findByCompanyAndYear($company, $selectedYear);

        $filename = \sprintf(
            'leaveflow-urlaubskonten-%s.csv',
            null === $selectedYear ? 'alle-jahre' : (string) $selectedYear,
        );

        // String buffer instead of StreamedResponse: at SMB scale (<50
        // employees × few years) the result is a few KiB and the test
        // crawler handles String-bodies cleanly, while StreamedResponse
        // forces ob-juggling in every test.
        $handle = fopen('php://temp', 'w+');
        if (false === $handle) {
            throw new \RuntimeException('Could not open temp stream for CSV export.');
        }
        // UTF-8 BOM so Excel/Numbers respect umlauts on first open.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            $this->translator->trans('admin.entitlements.export.col.employee'),
            $this->translator->trans('admin.entitlements.export.col.employee_number'),
            $this->translator->trans('admin.entitlements.export.col.year'),
            $this->translator->trans('admin.entitlements.export.col.type'),
            $this->translator->trans('admin.entitlements.export.col.granted'),
            $this->translator->trans('admin.entitlements.export.col.used'),
            $this->translator->trans('admin.entitlements.export.col.remaining'),
            $this->translator->trans('admin.entitlements.export.col.expires_at'),
        ], ';', '"', '');
        foreach ($entries as $entry) {
            $employee = $entry->getEmployee();
            fputcsv($handle, [
                $employee->getFullName(),
                $employee->getEmployeeNumber(),
                (string) $entry->getYear(),
                LeaveEntitlementType::Regular === $entry->getType()
                    ? $this->translator->trans('admin.entitlements.type.regular')
                    : $this->translator->trans('admin.entitlements.type.carryover'),
                number_format($entry->getHoursGranted(), 2, ',', ''),
                number_format($entry->getHoursUsed(), 2, ',', ''),
                number_format($entry->getHoursRemaining(), 2, ',', ''),
                $entry->getExpiresAt()?->format('Y-m-d') ?? '',
            ], ';', '"', '');
        }
        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response(
            $body,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => \sprintf('attachment; filename="%s"', $filename),
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    #[Route('/pro-rata-hint', name: 'pro_rata_hint', methods: ['GET'])]
    public function proRataHintFragment(Request $request): Response
    {
        $company = $this->requireCompany();
        $employeeId = $request->query->getInt('employee');
        $year = $request->query->getInt('year');
        $typeRaw = $request->query->getString('type', '');

        $employee = $employeeId > 0
            ? $this->employeeRepository->findOneBy(['id' => $employeeId, 'company' => $company])
            : null;

        $type = '' !== $typeRaw ? LeaveEntitlementType::tryFrom($typeRaw) : null;

        return $this->render('admin/entitlements/_pro_rata_hint.html.twig', [
            'proRataHint' => $this->buildProRataHint($employee, $year, $type),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(LeaveEntitlementFormType::class, null, ['company' => $company]);
        // Sensible defaults: current year, Regular type.
        $form->get('year')->setData((int) (new \DateTimeImmutable())->format('Y'));
        $form->get('type')->setData(LeaveEntitlementType::Regular);
        $form->handleRequest($request);

        $proRataHint = $this->buildProRataHint(
            $form->get('employee')->getData(),
            $form->get('year')->getData(),
            $form->get('type')->getData(),
        );

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Employee $employee */
            $employee = $form->get('employee')->getData();
            /** @var int $year */
            $year = $form->get('year')->getData();
            /** @var LeaveEntitlementType $type */
            $type = $form->get('type')->getData();
            $hoursGranted = (float) $form->get('hoursGranted')->getData();
            /** @var \DateTimeImmutable|null $expiresAt */
            $expiresAt = $form->get('expiresAt')->getData();

            try {
                $entry = new LeaveEntitlement($employee, $year, $type, $hoursGranted, $expiresAt);
                $this->entityManager->persist($entry);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    $this->translator->trans('admin.entitlements.flash.duplicate')
                ));

                return $this->render('admin/entitlements/form.html.twig', [
                    'form' => $form,
                    'proRataHint' => $proRataHint,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->render('admin/entitlements/form.html.twig', [
                    'form' => $form,
                    'proRataHint' => $proRataHint,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.created'));

            return $this->redirectToRoute('app_admin_entitlement_index');
        }

        return $this->render('admin/entitlements/form.html.twig', [
            'form' => $form,
            'proRataHint' => $proRataHint,
        ]);
    }

    #[Route('/{id}/expires', name: 'edit_expiry', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editExpiry(Request $request, LeaveEntitlement $entry): Response
    {
        // Guard: entitlement must belong to the current company.
        $company = $this->requireCompany();
        if ($entry->getEmployee()->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        // Guard: only Carryover entries have an expiry — Regular vacation uses
        // the year itself as deadline. Direct URL access on a Regular entry
        // returns 404.
        if (LeaveEntitlementType::Carryover !== $entry->getType()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LeaveEntitlementExpiresAtFormType::class, null, [
            'entitlement_year' => $entry->getYear(),
        ]);
        $form->get('expiresAt')->setData($entry->getExpiresAt());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTimeImmutable|null $expiresAt */
            $expiresAt = $form->get('expiresAt')->getData();
            $reason = trim((string) $form->get('reason')->getData());
            $oldExpiry = $entry->getExpiresAt();

            // Same-day no-op: skip mutation and audit. format(Y-m-d) keeps
            // the comparison time-zone-agnostic and tolerant of the form
            // returning a midnight DateTimeImmutable while the entity may
            // already hold one.
            $oldKey = $oldExpiry?->format('Y-m-d');
            $newKey = $expiresAt?->format('Y-m-d');
            if ($oldKey === $newKey) {
                $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.no_change'));

                return $this->redirectToRoute('app_admin_entitlement_index');
            }

            try {
                $entry->adjustExpiresAt($expiresAt);
                $this->entityManager->persist(LeaveEntitlementAuditEntry::forExpiryAdjustment(
                    entitlement: $entry,
                    actor: $this->resolveAdminEmployee($company),
                    oldExpiresAt: $oldExpiry,
                    newExpiresAt: $expiresAt,
                    occurredAt: $this->clock->now(),
                    reason: $reason,
                ));
                $this->entityManager->flush();
            } catch (\InvalidArgumentException $e) {
                // Defense in depth: form-level validation should have caught
                // this. Surface as form error if the entity guard still fires.
                $form->get('expiresAt')->addError(new FormError($e->getMessage()));

                return $this->render('admin/entitlements/edit_expiry.html.twig', [
                    'form' => $form,
                    'entry' => $entry,
                    'auditHistory' => $this->auditRepository->findByEntitlement($entry),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.expiry_updated'));

            return $this->redirectToRoute('app_admin_entitlement_index');
        }

        $status = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/entitlements/edit_expiry.html.twig', [
            'form' => $form,
            'entry' => $entry,
            'auditHistory' => $this->auditRepository->findByEntitlement($entry),
        ], new Response('', $status));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, LeaveEntitlement $entry): Response
    {
        $company = $this->requireCompany();
        if ($entry->getEmployee()->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LeaveEntitlementGrantedHoursFormType::class);
        $form->get('hoursGranted')->setData($entry->getHoursGranted());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newGrant = (float) $form->get('hoursGranted')->getData();
            $reason = trim((string) $form->get('reason')->getData());
            $oldGrant = $entry->getHoursGranted();

            // No-op edit (admin opened the form, didn't change the value):
            // skip both the domain mutation and the audit write — the
            // audit factory enforces "must record an actual change" anyway,
            // catching it here keeps the user-facing flash honest.
            if (abs($oldGrant - $newGrant) < 0.0001) {
                $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.no_change'));

                return $this->redirectToRoute('app_admin_entitlement_index', ['year' => $entry->getYear()]);
            }

            try {
                $entry->adjustGrantedHours($newGrant);
                $this->entityManager->persist(LeaveEntitlementAuditEntry::forHoursAdjustment(
                    entitlement: $entry,
                    actor: $this->resolveAdminEmployee($company),
                    oldHoursGranted: $oldGrant,
                    newHoursGranted: $newGrant,
                    occurredAt: $this->clock->now(),
                    reason: $reason,
                ));
                $this->entityManager->flush();
            } catch (\InvalidArgumentException|\DomainException $e) {
                $form->get('hoursGranted')->addError(new FormError($e->getMessage()));

                return $this->render('admin/entitlements/edit.html.twig', [
                    'form' => $form,
                    'entry' => $entry,
                    'auditHistory' => $this->auditRepository->findByEntitlement($entry),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.updated'));

            return $this->redirectToRoute('app_admin_entitlement_index', ['year' => $entry->getYear()]);
        }

        $status = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/entitlements/edit.html.twig', [
            'form' => $form,
            'entry' => $entry,
            'auditHistory' => $this->auditRepository->findByEntitlement($entry),
        ], new Response('', $status));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, LeaveEntitlement $entry): Response
    {
        $company = $this->requireCompany();
        if ($entry->getEmployee()->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('entitlement-delete-'.$entry->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        // Hard guard: deleting an entitlement that already booked hours would
        // leave LeaveRequest.hoursUsed orphaned and silently corrupt balances.
        // Force the admin to first reverse approvals (cancel + confirm_cancel)
        // before the entitlement can go.
        if ($entry->getHoursUsed() > 0) {
            $this->addFlash('error', $this->translator->trans('admin.entitlements.flash.delete_blocked', [
                '%hoursUsed%' => number_format($entry->getHoursUsed(), 2, ',', '.'),
            ]));

            return $this->redirectToRoute('app_admin_entitlement_index', ['year' => $entry->getYear()]);
        }

        $year = $entry->getYear();
        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.entitlements.flash.deleted'));

        return $this->redirectToRoute('app_admin_entitlement_index', ['year' => $year]);
    }

    /**
     * Returns pro-rata hint data when an employee joined mid-year and a Regular
     * entitlement is being created for that year. Returns null otherwise so the
     * template can skip the banner entirely.
     *
     * @return array{joinedAt: \DateTimeImmutable, effectiveMonths: int, year: int}|null
     */
    private function buildProRataHint(mixed $employee, mixed $year, mixed $type): ?array
    {
        if (!$employee instanceof Employee || !\is_int($year) || LeaveEntitlementType::Regular !== $type) {
            return null;
        }
        if (!$this->proRataCalculator->isReducedEntitlement($employee->getJoinedAt(), $year)) {
            return null;
        }

        $joinDay = (int) $employee->getJoinedAt()->format('j');
        $joinMonth = (int) $employee->getJoinedAt()->format('n');
        $firstCounted = $joinDay <= 15 ? $joinMonth : $joinMonth + 1;
        $effectiveMonths = max(0, 12 - $firstCounted + 1);

        return [
            'joinedAt' => $employee->getJoinedAt(),
            'effectiveMonths' => $effectiveMonths,
            'year' => $year,
        ];
    }

    private function requireCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }

    /**
     * Maps the logged-in admin User to its Employee for the audit-trail
     * actor. Returns null for IT-only admin accounts that don't have an
     * HR record — the audit entry's actor is nullable, and the history
     * panel falls back to a generic "Administrator" label in that case.
     */
    private function resolveAdminEmployee(Company $company): ?Employee
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $employee = $this->employeeRepository->findOneBy(['user' => $user]);
        if (!$employee instanceof Employee || $employee->getCompany() !== $company) {
            return null;
        }

        return $employee;
    }
}
