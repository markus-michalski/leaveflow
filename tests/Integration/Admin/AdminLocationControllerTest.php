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
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminLocationControllerTest extends WebTestCase
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
    public function adminSeesEmptyLocationList(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/locations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Standorte');
    }

    #[Test]
    public function adminCanCreateLocation(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/locations/new');
        $form = $crawler->filter('form[data-testid="admin-location-form"]')->form();

        $formName = $form->getName();
        $this->client->submit($form, [
            $formName.'[name]' => 'Niederlassung Hamburg',
            $formName.'[country]' => 'de',
            $formName.'[federalState]' => 'de-hh',
            $formName.'[city]' => 'Hamburg',
        ]);

        self::assertResponseRedirects('/admin/locations');

        /** @var Location|null $created */
        $created = $this->em->getRepository(Location::class)->findOneBy(['name' => 'Niederlassung Hamburg']);
        self::assertNotNull($created);
        self::assertSame('DE', $created->getCountry());
        self::assertSame('DE-HH', $created->getFederalState());
    }

    #[Test]
    public function employeeIsForbiddenFromLocationManagement(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/admin/locations');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function seed(): void
    {
        $company = new Company('Acme GmbH', 36);
        $this->em->persist($company);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
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
