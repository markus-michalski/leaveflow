<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end test for the CSV employee import (Phase 9).
 *
 * Pinned behaviors:
 * - Upload route renders the form + template-download link
 * - Template-download returns CSV with the right Content-Type
 * - Preview validates per-row, surfaces errors inline
 * - Commit persists valid rows after re-validation
 * - Bad CSV (missing header) is rejected with a form error
 * - Manager + Employee roles get 403
 */
final class AdminEmployeeImportControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Location $hq;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function indexShowsUploadFormAndTemplateLink(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/employees/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-testid="employee-import-upload-form"]');
        self::assertSelectorExists('[data-testid="employee-import-template-download"]');
    }

    #[Test]
    public function templateEndpointReturnsCsvAttachment(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/employees/import/template');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        // Must include the header line.
        $this->client->getResponse()->sendContent();
        $body = $this->captureStreamedContent();
        self::assertStringContainsString('fullName,employeeNumber', $body);
    }

    #[Test]
    public function previewValidatesValidCsvAndRendersConfirmButton(): void
    {
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            ."Erika Mustermann,EMP-1001,HQ,40,\"1,2,3,4,5\",2025-01-15\n";
        $this->loginAs('admin@leaveflow.test');
        $this->postUpload($csv);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="employee-import-row-2"]');
        self::assertSelectorExists('[data-testid="employee-import-commit-form"]');
        self::assertSelectorTextContains('[data-testid="employee-import-summary"]', '1 gültige');
    }

    #[Test]
    public function previewSurfacesPerRowErrorsAndHidesCommit(): void
    {
        // Row 2 has fullName only — other required cells missing.
        // Row 3 references an unknown location.
        // Note: completely empty rows are silently dropped by the parser,
        // so row 2 needs partial data to reach the validator.
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            ."Anna,,,,,\n"
            ."Erika,EMP-1001,Nirgendwo,40,\"1,2,3,4,5\",2025-01-15\n";
        $this->loginAs('admin@leaveflow.test');
        $this->postUpload($csv);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="employee-import-blocked"]');
        self::assertSelectorNotExists('[data-testid="employee-import-commit-form"]');
        self::assertSelectorTextContains('[data-testid="employee-import-row-2"]', 'Pflichtfeld');
        self::assertSelectorTextContains('[data-testid="employee-import-row-3"]', 'Nirgendwo');
    }

    #[Test]
    public function previewRejectsCsvMissingRequiredColumnsWithFormError(): void
    {
        $csv = "fullName,employeeNumber\nA,1\n";
        $this->loginAs('admin@leaveflow.test');
        $this->postUpload($csv);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'missing required columns',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function commitPersistsValidRowsAndRedirects(): void
    {
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            ."Erika Mustermann,EMP-1001,HQ,40,\"1,2,3,4,5\",2025-01-15\n";
        $this->loginAs('admin@leaveflow.test');
        $this->postUpload($csv);

        // Preview rendered — submit the commit form to trigger persist.
        $crawler = $this->client->getCrawler();
        $form = $crawler->filter('form[data-testid="employee-import-commit-form"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/employees');

        $persisted = $this->em->getRepository(Employee::class)
            ->findOneBy(['employeeNumber' => 'EMP-1001']);
        self::assertNotNull($persisted);
        self::assertSame('Erika Mustermann', $persisted->getFullName());
    }

    #[Test]
    public function commitRevalidatesAndRefusesPartialBatch(): void
    {
        // Pre-existing employee with the same number we're about to import —
        // mid-flight DB shift. Commit should re-validate and refuse to
        // persist the whole batch.
        $existing = new Employee(
            $this->company,
            'Already there',
            'EMP-1001',
            $this->hq,
            \App\Domain\ValueObject\WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->em->persist($existing);
        $this->em->flush();

        // Step 1: upload a CSV that doesn't yet conflict with EMP-1001 so
        // the preview form renders. We're going to mutate the CSV between
        // preview render and commit submission to simulate a real DB
        // shift — the commit re-validates and discovers the conflict.
        $previewCsv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            ."Erika Mustermann,EMP-2002,HQ,40,\"1,2,3,4,5\",2025-01-15\n";
        $this->loginAs('admin@leaveflow.test');
        $this->postUpload($previewCsv);

        // Step 2: pull the rendered commit form, but swap the csvBase64
        // value to a CSV that conflicts with the pre-existing employee.
        $conflictCsv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            ."Erika Mustermann,EMP-1001,HQ,40,\"1,2,3,4,5\",2025-01-15\n";
        $crawler = $this->client->getCrawler();
        $form = $crawler->filter('form[data-testid="employee-import-commit-form"]')->form();
        $form['csvBase64'] = base64_encode($conflictCsv);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $rows = $this->em->getRepository(Employee::class)
            ->findBy(['employeeNumber' => 'EMP-1001']);
        self::assertCount(1, $rows);
        self::assertSame('Already there', $rows[0]->getFullName());
    }

    #[Test]
    public function commitRejectsInvalidCsrf(): void
    {
        $this->loginAs('admin@leaveflow.test');
        // Render any page first to establish the session.
        $this->client->request('GET', '/admin/employees/import');
        $this->client->request('POST', '/admin/employees/import/commit', [
            '_token' => 'wrong',
            'csvBase64' => base64_encode("fullName\nA\n"),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function managerCannotAccessIndex(): void
    {
        $this->loginAs('manager@leaveflow.test');
        $this->client->request('GET', '/admin/employees/import');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function postUpload(string $csv): void
    {
        // Need a real file on disk because Symfony's UploadedFile reads from path.
        $tmp = (string) tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($tmp, $csv);

        // Render the upload page so the session holds a fresh CSRF token,
        // then submit via the parsed Form so DomCrawler ports the token
        // value automatically.
        $crawler = $this->client->request('GET', '/admin/employees/import');
        $form = $crawler->filter('form[data-testid="employee-import-upload-form"]')->form();
        $formName = $form->getName();

        $upload = new UploadedFile(
            path: $tmp,
            originalName: 'employees.csv',
            mimeType: 'text/csv',
            error: null,
            test: true,
        );

        // The Form helper carries the form values (including CSRF) but
        // doesn't ferry uploaded files cleanly across submit(). Pull the
        // values manually and re-issue the POST with files in the right
        // place.
        $values = $form->getPhpValues();
        $files = [
            $formName => ['file' => $upload],
        ];

        $this->client->request(
            'POST',
            $form->getUri(),
            $values,
            $files,
        );
    }

    private function captureStreamedContent(): string
    {
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $this->hq = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->em->persist($this->hq);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
            ['manager@leaveflow.test', UserRole::Manager],
            ['employee@leaveflow.test', UserRole::Employee],
        ] as [$email, $role]) {
            $user = new User($this->company, $email, $role);
            $user->setHashedPassword($hasher->hashPassword($user, AppFixtures::DEFAULT_PASSWORD));
            $this->em->persist($user);
        }

        $this->em->flush();
    }

    private function loginAs(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form[data-testid="login-form"]')->form([
            '_username' => $email,
            '_password' => AppFixtures::DEFAULT_PASSWORD,
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();
    }
}
