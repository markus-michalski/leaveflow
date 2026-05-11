<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminStatisticsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Employee $alice;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesStatisticsDashboard(): void
    {
        $entitlement = new LeaveEntitlement($this->alice, 2026, LeaveEntitlementType::Regular, 240.0);
        $entitlement->consume(80.0);
        $this->em->persist($entitlement);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/statistics?year=2026');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Statistik');
        self::assertSelectorExists('[data-testid="kpi-utilization"]');
        self::assertSelectorExists('[data-testid="kpi-illness"]');
        self::assertSelectorExists('[data-testid="kpi-pending"]');
        self::assertSelectorExists('[data-testid="kpi-avg-remaining"]');
        self::assertSelectorExists('[data-testid="chart-monthly"]');
        self::assertSelectorExists('[data-testid="chart-departments"]');
        // 80 / 240 = 33.3% utilization, formatted with German locale
        self::assertSelectorTextContains('[data-testid="kpi-utilization"]', '33,3');
    }

    #[Test]
    public function nonAdminCannotAccessStatistics(): void
    {
        $this->loginAs('manager@leaveflow.test');
        $this->client->request('GET', '/admin/statistics');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function defaultsToCurrentYearWhenNoQueryGiven(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/statistics');

        self::assertResponseIsSuccessful();
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');
        self::assertSelectorTextContains(
            '[data-testid="admin-statistics-year-select"] option[selected]',
            (string) $currentYear,
        );
    }

    #[Test]
    public function clampsInvalidYearToCurrentYear(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/statistics?year=3000');

        self::assertResponseIsSuccessful();
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');
        self::assertSelectorTextContains(
            '[data-testid="admin-statistics-year-select"] option[selected]',
            (string) $currentYear,
        );
    }

    #[Test]
    public function pdfExportReturnsDownloadableFile(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/statistics/export.pdf?year=2026');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        $contentDisposition = $response->headers->get('Content-Disposition') ?? '';
        self::assertStringContainsString('attachment', $contentDisposition);
        self::assertStringContainsString('leaveflow-statistik-2026.pdf', $contentDisposition);
        // PDF magic number — the body is a real PDF, not an HTML error page.
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    #[Test]
    public function pdfExportRequiresAdminRole(): void
    {
        $this->loginAs('manager@leaveflow.test');
        $this->client->request('GET', '/admin/statistics/export.pdf');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH');
        $this->em->persist($this->company);

        $location = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->em->persist($location);

        $this->alice = new Employee(
            $this->company,
            'Alice',
            'EMP-1',
            $location,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->em->persist($this->alice);

        $vacation = new AbsenceType(
            $this->company,
            'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->em->persist($vacation);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
            ['manager@leaveflow.test', UserRole::Manager],
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
