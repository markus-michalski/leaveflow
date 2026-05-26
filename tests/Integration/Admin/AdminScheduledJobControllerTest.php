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

namespace App\Tests\Integration\Admin;

use App\Application\Entitlement\YearTransitionHandler;
use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\ScheduledJobConfig;
use App\Domain\Entity\User;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Integration tests for the Phase 3 admin UI for scheduled jobs (#35).
 *
 * Pinned behaviors:
 * - Index lists every job in the registry, even those without a DB row yet
 * - Status badge reflects either the persisted DB toggle or default-true
 *   semantics for missing rows
 * - Toggle action flips the row (auto-provisioning if needed) and surfaces
 *   a flash message with the new state
 * - Unknown job names return 404
 * - CSRF protected
 * - Manager + Employee forbidden
 */
final class AdminScheduledJobControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function indexListsAllRegistryJobs(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/scheduled-jobs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Cron-Jobs');
        // The three current jobs from the registry — pinned by name.
        self::assertSelectorExists('[data-testid="scheduled-job-row-year-transition"]');
        self::assertSelectorExists('[data-testid="scheduled-job-row-entitlement-expiry-check"]');
        self::assertSelectorExists('[data-testid="scheduled-job-row-approval-escalation-check"]');
    }

    #[Test]
    public function jobWithoutDbRowDefaultsToEnabled(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/scheduled-jobs');

        // No row in scheduled_job_configs yet — the badge defaults to "Aktiv"
        // because handlers also default to enabled before their first run.
        self::assertSelectorTextContains(
            '[data-testid="scheduled-job-status-year-transition"]',
            'Aktiv',
        );
    }

    #[Test]
    public function jobWithDisabledRowShowsDisabledBadge(): void
    {
        $config = new ScheduledJobConfig(YearTransitionHandler::JOB_NAME);
        $config->disable();
        $this->em->persist($config);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/scheduled-jobs');

        self::assertSelectorTextContains(
            '[data-testid="scheduled-job-status-year-transition"]',
            'Deaktiviert',
        );
    }

    #[Test]
    public function lastRunErrorIsTruncatedAndShown(): void
    {
        $config = new ScheduledJobConfig(YearTransitionHandler::JOB_NAME);
        $config->recordRun(
            new \DateTimeImmutable('2026-12-31 10:00:00'),
            ScheduledJobRunStatus::Failure,
            'database connection refused',
        );
        $this->em->persist($config);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/scheduled-jobs');

        self::assertSelectorTextContains(
            '[data-testid="scheduled-job-row-year-transition"]',
            'database connection refused',
        );
    }

    #[Test]
    public function toggleDisablesEnabledJob(): void
    {
        // Pre-existing enabled row — toggle should disable it.
        $config = new ScheduledJobConfig(YearTransitionHandler::JOB_NAME);
        $this->em->persist($config);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/scheduled-jobs');
        $form = $crawler->filter('form[data-testid="scheduled-job-toggle-form-year-transition"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/scheduled-jobs');

        $this->em->clear();
        $reloaded = $this->em->getRepository(ScheduledJobConfig::class)
            ->findOneBy(['name' => YearTransitionHandler::JOB_NAME]);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isEnabled());
    }

    #[Test]
    public function toggleAutoCreatesRowForJobWithoutDbState(): void
    {
        // No pre-existing row. The toggle action needs to provision one
        // and flip default-true to false so the admin's intent is recorded.
        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/scheduled-jobs');
        $form = $crawler->filter('form[data-testid="scheduled-job-toggle-form-year-transition"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/scheduled-jobs');

        $this->em->clear();
        $created = $this->em->getRepository(ScheduledJobConfig::class)
            ->findOneBy(['name' => YearTransitionHandler::JOB_NAME]);
        self::assertNotNull($created);
        self::assertFalse($created->isEnabled());
    }

    #[Test]
    public function toggleEnablesPreviouslyDisabledJob(): void
    {
        $config = new ScheduledJobConfig(YearTransitionHandler::JOB_NAME);
        $config->disable();
        $this->em->persist($config);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/scheduled-jobs');
        $form = $crawler->filter('form[data-testid="scheduled-job-toggle-form-year-transition"]')->form();
        $this->client->submit($form);

        $this->em->clear();
        $reloaded = $this->em->getRepository(ScheduledJobConfig::class)
            ->findOneBy(['name' => YearTransitionHandler::JOB_NAME]);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isEnabled());
    }

    #[Test]
    public function toggleUnknownJobReturns404(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('POST', '/admin/scheduled-jobs/does-not-exist/toggle', [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function toggleRejectsInvalidCsrf(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('POST', '/admin/scheduled-jobs/year-transition/toggle', [
            '_token' => 'wrong',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function managerCannotAccessIndex(): void
    {
        $this->loginAs('manager@leaveflow.test');
        $this->client->request('GET', '/admin/scheduled-jobs');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function employeeCannotAccessIndex(): void
    {
        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/admin/scheduled-jobs');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function seed(): void
    {
        $company = new Company('Acme GmbH', 36);
        $this->em->persist($company);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
            ['manager@leaveflow.test', UserRole::Manager],
            ['employee@leaveflow.test', UserRole::Employee],
        ] as [$email, $role]) {
            $user = new User($company, $email, $role);
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
