<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 9 issues #3 (filter + search) and #4 (pagination) for /admin/users.
 *
 * Pinned behaviors:
 * - status filter narrows to active or inactive
 * - search matches both User.email and Employee.fullName
 * - pagination kicks in past 25 rows; out-of-range page numbers clamp
 * - URL params compose so links / shares work as expected
 */
final class AdminUserControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Location $location;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function indexRendersUsers(): void
    {
        $alice = $this->makeUser('alice@acme.test', UserRole::Employee);
        $bob = $this->makeUser('bob@acme.test', UserRole::Employee);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-row-'.$alice->getId().'"]');
        self::assertSelectorExists('[data-testid="user-row-'.$bob->getId().'"]');
    }

    #[Test]
    public function statusFilterActiveExcludesDeactivatedUsers(): void
    {
        $active = $this->makeUser('active-user@acme.test', UserRole::Employee);
        $deactivated = $this->makeUser('deactivated-user@acme.test', UserRole::Employee);
        $deactivated->deactivate();
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/users?status=active');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-row-'.$active->getId().'"]');
        self::assertSelectorNotExists('[data-testid="user-row-'.$deactivated->getId().'"]');
    }

    #[Test]
    public function statusFilterInactiveExcludesActiveUsers(): void
    {
        $active = $this->makeUser('active-user@acme.test', UserRole::Employee);
        $deactivated = $this->makeUser('deactivated-user@acme.test', UserRole::Employee);
        $deactivated->deactivate();
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/users?status=inactive');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-row-'.$deactivated->getId().'"]');
        self::assertSelectorNotExists('[data-testid="user-row-'.$active->getId().'"]');
    }

    #[Test]
    public function searchMatchesEmailSubstring(): void
    {
        $jane = $this->makeUser('jane.doe@acme.test', UserRole::Employee);
        $bob = $this->makeUser('bob.builder@acme.test', UserRole::Employee);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/users?q=jane');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-row-'.$jane->getId().'"]');
        self::assertSelectorNotExists('[data-testid="user-row-'.$bob->getId().'"]');
    }

    #[Test]
    public function searchMatchesEmployeeFullName(): void
    {
        // Employee with fullName "Jane Doe", but email doesn't contain "jane" —
        // the join lets the search find them by name.
        $jane = $this->makeUser('xyz@acme.test', UserRole::Employee);
        $this->em->persist(new Employee(
            $this->company,
            'Jane Doe',
            'EMP-J001',
            $this->location,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
            $jane,
        ));
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/users?q=jane');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-row-'.$jane->getId().'"]');
    }

    #[Test]
    public function searchIsCaseInsensitive(): void
    {
        $alice = $this->makeUser('alice.SMITH@acme.test', UserRole::Employee);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/users?q=SMITH');

        self::assertResponseIsSuccessful();
        // User entity normalizes email to lowercase on construction; search needle
        // is also lower-cased before LIKE — both halves of the comparison agree.
        self::assertSelectorExists('[data-testid="user-row-'.$alice->getId().'"]');
    }

    #[Test]
    public function pagedListShowsAtMost25UsersPerPage(): void
    {
        $createdIds = [];
        // Add admin + 30 others = 31 total, exceeds 25/page.
        for ($i = 1; $i <= 30; ++$i) {
            $u = $this->makeUser(\sprintf('user%02d@acme.test', $i), UserRole::Employee);
            $createdIds[] = $u->getId();
        }
        $this->em->flush();

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        $rowCount = $crawler->filter('[data-testid^="user-row-"]')->count();
        // Default page = 1, 25 results.
        self::assertSame(25, $rowCount);
        self::assertSelectorExists('[data-testid="pagination"]');
    }

    #[Test]
    public function paginationSecondPageReturnsRemainingUsers(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->makeUser(\sprintf('user%02d@acme.test', $i), UserRole::Employee);
        }
        $this->em->flush();

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/admin/users?page=2');

        self::assertResponseIsSuccessful();
        // 31 total (30 created + admin), 25 on page 1, 6 on page 2.
        $rowCount = $crawler->filter('[data-testid^="user-row-"]')->count();
        self::assertSame(6, $rowCount);
    }

    #[Test]
    public function paginationOutOfRangePageClampsToLastPage(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->makeUser(\sprintf('user%02d@acme.test', $i), UserRole::Employee);
        }
        $this->em->flush();

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/admin/users?page=99');

        self::assertResponseIsSuccessful();
        // Should clamp to last page (page 2 with 6 rows), not return 404.
        $rowCount = $crawler->filter('[data-testid^="user-row-"]')->count();
        self::assertSame(6, $rowCount);
    }

    #[Test]
    public function emptyResultShowsEmptyState(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/users?q=xyz-nothing-matches');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="admin-users-empty"]');
    }

    private function makeUser(string $email, UserRole $role): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User($this->company, $email, $role);
        $user->setHashedPassword($hasher->hashPassword($user, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($user);

        return $user;
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $this->location = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($this->location);

        $this->makeUser('admin@leaveflow.test', UserRole::Admin);

        $this->em->flush();
    }

    private function loginAsAdmin(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form[data-testid="login-form"]')->form([
            '_username' => 'admin@leaveflow.test',
            '_password' => AppFixtures::DEFAULT_PASSWORD,
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();
    }
}
