<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Import\CsvParseException;
use App\Application\Import\EmployeeImportService;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Presentation\Form\EmployeeImportUploadFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Two-step CSV import for employees (Phase 9).
 *
 * Flow:
 * 1. GET  /import           — upload form + template download link
 * 2. POST /import/preview   — validates the file, renders per-row results.
 *                             If all rows valid: hidden field carries the
 *                             base64-encoded CSV through to commit.
 * 3. POST /import/commit    — re-validates (DB may have shifted) and
 *                             persists if every row still passes.
 * 4. GET  /import/template  — downloads a ready-to-fill CSV template.
 *
 * The base64 round-trip avoids server-side temp files for what's typically
 * a sub-10KB CSV. If files grow past ~100KB switch to a cache.app-backed
 * key — but for SMB scale the inline form value is the simpler path.
 */
#[Route('/admin/employees/import', name: 'app_admin_employee_import_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEmployeeImportController extends AbstractController
{
    public function __construct(
        private readonly EmployeeImportService $service,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/employees/import/upload.html.twig', [
            'form' => $this->createForm(EmployeeImportUploadFormType::class),
        ]);
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $form = $this->createForm(EmployeeImportUploadFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/employees/import/upload.html.twig', [
                'form' => $form,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $upload = $form->get('file')->getData();
        if (null === $upload) {
            throw new BadRequestHttpException('No file in upload.');
        }
        $csv = (string) file_get_contents($upload->getPathname());

        try {
            $results = $this->service->preview($csv, $this->currentCompany());
        } catch (CsvParseException $e) {
            $form->get('file')->addError(new FormError($e->getMessage()));

            return $this->render('admin/employees/import/upload.html.twig', [
                'form' => $form,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/employees/import/preview.html.twig', [
            'results' => $results,
            'csvBase64' => base64_encode($csv),
            'allValid' => $this->allValid($results),
            'validCount' => $this->validCount($results),
            'invalidCount' => $this->invalidCount($results),
        ]);
    }

    #[Route('/commit', name: 'commit', methods: ['POST'])]
    public function commit(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('employee-import-commit', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $base64 = (string) $request->request->get('csvBase64', '');
        $csv = base64_decode($base64, true);
        if (false === $csv || '' === $csv) {
            throw new BadRequestHttpException('CSV payload missing or corrupt.');
        }

        try {
            $results = $this->service->commit($csv, $this->currentCompany());
        } catch (CsvParseException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_employee_import_index');
        }

        $invalidCount = $this->invalidCount($results);
        if ($invalidCount > 0) {
            // DB shifted between preview and commit — show preview again
            // with the fresh validation result instead of silently
            // dropping rows.
            $this->addFlash('error', $this->translator->trans('admin.employees.import.flash.revalidation_failed'));

            return $this->render('admin/employees/import/preview.html.twig', [
                'results' => $results,
                'csvBase64' => $base64,
                'allValid' => false,
                'validCount' => $this->validCount($results),
                'invalidCount' => $invalidCount,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->addFlash('success', $this->translator->trans(
            'admin.employees.import.flash.success',
            ['%count%' => $this->validCount($results)],
        ));

        return $this->redirectToRoute('app_admin_employee_index');
    }

    #[Route('/template', name: 'template', methods: ['GET'])]
    public function template(): Response
    {
        // Header + two example rows so admins see the expected shape.
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt,leftAt,userEmail,departmentName\n"
            ."Erika Mustermann,EMP-1001,Hauptsitz München,40,\"1,2,3,4,5\",2025-01-15,,erika@example.test,Entwicklung\n"
            ."Max Beispiel,EMP-1002,Standort Berlin,30,\"1,2,3,4\",2024-09-01,,,\n";

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="leaveflow-employees-template.csv"');

        return $response;
    }

    /**
     * @param list<\App\Application\Import\EmployeeImportRowResult> $results
     */
    private function allValid(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->isValid()) {
                return false;
            }
        }

        return [] !== $results;
    }

    /**
     * @param list<\App\Application\Import\EmployeeImportRowResult> $results
     */
    private function validCount(array $results): int
    {
        return \count(array_filter($results, static fn ($r): bool => $r->isValid()));
    }

    /**
     * @param list<\App\Application\Import\EmployeeImportRowResult> $results
     */
    private function invalidCount(array $results): int
    {
        return \count(array_filter($results, static fn ($r): bool => !$r->isValid()));
    }

    private function currentCompany(): Company
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user->getCompany();
    }
}
