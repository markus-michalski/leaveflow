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

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use App\Domain\Entity\HolidayOverride;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminHolidayControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesCalendarForCurrentYear(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/holidays');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Feiertagskalender');
    }

    #[Test]
    public function adminCanFilterByYearAndState(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/holidays?year=2025&state=DE-SN');

        self::assertResponseIsSuccessful();
        // Saxony has Buss- und Bettag on 2025-11-19.
        self::assertSelectorTextContains('body', '19.11.2025');
    }

    #[Test]
    public function invalidYearFallsBackToCurrent(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/holidays?year=1800&state=DE-BY');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function employeeIsForbidden(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/admin/holidays');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function adminCanCreateOverride(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/holidays/overrides/new');
        $form = $crawler->filter('form[data-testid="override-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[federalState]' => 'DE-BY',
            $formName.'[date]' => '08.08.2025',
            $formName.'[name]' => 'Friedensfest Augsburg',
            $formName.'[type]' => 'added',
        ]);

        self::assertResponseRedirects('/admin/holidays/overrides');

        $found = $this->em->getRepository(HolidayOverride::class)->findOneBy(['name' => 'Friedensfest Augsburg']);
        self::assertInstanceOf(HolidayOverride::class, $found);
        self::assertSame(FederalState::Bayern, $found->getFederalState());
        self::assertSame(HolidayOverrideType::Added, $found->getType());
    }

    #[Test]
    public function duplicateOverrideRendersFormErrorWith422(): void
    {
        $existing = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2025-08-08'),
            'Friedensfest',
            HolidayOverrideType::Added,
        );
        $this->em->persist($existing);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/holidays/overrides/new');
        $form = $crawler->filter('form[data-testid="override-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[federalState]' => 'DE-BY',
            $formName.'[date]' => '08.08.2025',
            $formName.'[name]' => 'Another Friedensfest',
            $formName.'[type]' => 'added',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    public function adminCanDeleteOverride(): void
    {
        $override = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2025-08-08'),
            'Friedensfest',
            HolidayOverrideType::Added,
        );
        $this->em->persist($override);
        $this->em->flush();
        $id = $override->getId();

        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/holidays/overrides');
        $this->client->submitForm('Entfernen');

        self::assertResponseRedirects('/admin/holidays/overrides');
        self::assertNull($this->em->getRepository(HolidayOverride::class)->find($id));
    }

    #[Test]
    public function adminCanCreateCompanyHoliday(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/holidays/company/new');
        $form = $crawler->filter('form[data-testid="company-holiday-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[date]' => '30.05.2025',
            $formName.'[name]' => 'Brückentag',
        ]);

        self::assertResponseRedirects('/admin/holidays/company');

        $found = $this->em->getRepository(CompanyHoliday::class)->findOneBy(['name' => 'Brückentag']);
        self::assertInstanceOf(CompanyHoliday::class, $found);
    }

    #[Test]
    public function adminCanCreateLocationScopedOverride(): void
    {
        $augsburg = $this->em->getRepository(Location::class)->findOneBy(['name' => 'Augsburg']);
        \assert($augsburg instanceof Location);

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/holidays/overrides/new');
        $form = $crawler->filter('form[data-testid="override-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[federalState]' => 'DE-BY',
            $formName.'[date]' => '08.08.2026',
            $formName.'[name]' => 'Augsburger Friedensfest',
            $formName.'[type]' => 'added',
            $formName.'[location]' => (string) $augsburg->getId(),
        ]);

        self::assertResponseRedirects('/admin/holidays/overrides');

        $found = $this->em->getRepository(HolidayOverride::class)
            ->findOneBy(['name' => 'Augsburger Friedensfest']);
        self::assertInstanceOf(HolidayOverride::class, $found);
        self::assertNotNull($found->getLocation());
        self::assertSame($augsburg->getId(), $found->getLocation()->getId());
    }

    #[Test]
    public function stateWideAndLocationScopedOverridesCoexistOnSameDate(): void
    {
        // The state-wide entry stays for München / non-Augsburg offices,
        // the location-scoped entry adds Augsburger Friedensfest only for
        // employees based in Augsburg. The unique index allows both.
        $augsburg = $this->em->getRepository(Location::class)->findOneBy(['name' => 'Augsburg']);
        \assert($augsburg instanceof Location);

        $stateWide = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2026-08-08'),
            'State-wide adjustment',
            HolidayOverrideType::Removed,
        );
        $this->em->persist($stateWide);

        $locationScoped = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2026-08-08'),
            'Augsburger Friedensfest',
            HolidayOverrideType::Added,
            $augsburg,
        );
        $this->em->persist($locationScoped);
        $this->em->flush();

        $rows = $this->em->getRepository(HolidayOverride::class)->findBy([
            'company' => $this->company,
            'date' => new \DateTimeImmutable('2026-08-08'),
        ]);

        self::assertCount(2, $rows);
    }

    #[Test]
    public function duplicateStateWideOverrideStillRejectedOnApplicationLevel(): void
    {
        // MySQL's unique index treats NULL location_id values as distinct
        // — without the application guard, the controller would silently
        // accept a second state-wide override on the same date.
        $existing = new HolidayOverride(
            $this->company,
            FederalState::Bayern,
            new \DateTimeImmutable('2026-09-01'),
            'First state-wide',
            HolidayOverrideType::Added,
        );
        $this->em->persist($existing);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/holidays/overrides/new');
        $form = $crawler->filter('form[data-testid="override-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[federalState]' => 'DE-BY',
            $formName.'[date]' => '01.09.2026',
            $formName.'[name]' => 'Second state-wide',
            $formName.'[type]' => 'added',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(1, $this->em->getRepository(HolidayOverride::class)->findBy([
            'date' => new \DateTimeImmutable('2026-09-01'),
        ]));
    }

    #[Test]
    public function calendarIncludesCompanyHoliday(): void
    {
        $this->em->persist(new CompanyHoliday(
            $this->company,
            new \DateTimeImmutable('2025-05-30'),
            'Brückentag',
        ));
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/holidays?year=2025&state=DE-BY');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Brückentag');
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $augsburg = new Location($this->company, 'Augsburg', 'DE', FederalState::Bayern->value, 'Augsburg');
        $this->em->persist($augsburg);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
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
