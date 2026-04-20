<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAbsenceTypeControllerTest extends WebTestCase
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
    public function adminSeesAbsenceTypeList(): void
    {
        $entry = new AbsenceType(
            $this->company,
            'Urlaub',
            true,
            true,
            '#3B82F6',
        );
        $this->em->persist($entry);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/absence-types');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Urlaubsarten');
        self::assertSelectorExists('[data-testid="absence-type-row-'.$entry->getId().'"]');
    }

    #[Test]
    public function adminCreatesAbsenceType(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/absence-types/new');
        $form = $crawler->filter('form[data-testid="admin-absence-type-form"]')->form();
        $formName = $form->getName();

        // Unchecked checkboxes are represented by unsetting the field, not by "0".
        $form->remove($formName.'[deductsFromLeave]');

        $this->client->submit($form, [
            $formName.'[name]' => 'Home Office',
            $formName.'[requiresApproval]' => '1',
            $formName.'[color]' => '#22C55E',
            $formName.'[active]' => '1',
        ]);

        self::assertResponseRedirects('/admin/absence-types');

        /** @var AbsenceType|null $created */
        $created = $this->em->getRepository(AbsenceType::class)->findOneBy(['name' => 'Home Office']);
        self::assertNotNull($created);
        self::assertSame('#22C55E', $created->getColor());
        self::assertTrue($created->requiresApproval());
        self::assertFalse($created->deductsFromLeave());
        self::assertTrue($created->isActive());
    }

    #[Test]
    public function adminEditsAbsenceType(): void
    {
        $existing = new AbsenceType(
            $this->company,
            'Urlaub',
            true,
            true,
            '#3B82F6',
        );
        $this->em->persist($existing);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/absence-types/'.$existing->getId().'/edit');
        $form = $crawler->filter('form[data-testid="admin-absence-type-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[name]' => 'Jahresurlaub',
            $formName.'[deductsFromLeave]' => '1',
            $formName.'[requiresApproval]' => '1',
            $formName.'[color]' => '#F59E0B',
            $formName.'[active]' => '1',
        ]);

        self::assertResponseRedirects('/admin/absence-types');

        $this->em->clear();
        /** @var AbsenceType|null $updated */
        $updated = $this->em->getRepository(AbsenceType::class)->find($existing->getId());
        self::assertNotNull($updated);
        self::assertSame('Jahresurlaub', $updated->getName());
        self::assertSame('#F59E0B', $updated->getColor());
    }

    #[Test]
    public function duplicateNameShowsFormError(): void
    {
        $existing = new AbsenceType(
            $this->company,
            'Urlaub',
            true,
            true,
            '#3B82F6',
        );
        $this->em->persist($existing);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/absence-types/new');
        $form = $crawler->filter('form[data-testid="admin-absence-type-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[name]' => 'Urlaub',
            $formName.'[deductsFromLeave]' => '1',
            $formName.'[requiresApproval]' => '1',
            $formName.'[color]' => '#3B82F6',
            $formName.'[active]' => '1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('bereits', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function adminDeletesAbsenceType(): void
    {
        $entry = new AbsenceType(
            $this->company,
            'Unpaid Leave',
            false,
            true,
            '#64748B',
        );
        $this->em->persist($entry);
        $this->em->flush();
        $id = $entry->getId();

        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/absence-types');
        $this->client->submitForm('Löschen');

        self::assertResponseRedirects('/admin/absence-types');
        self::assertNull($this->em->getRepository(AbsenceType::class)->find($id));
    }

    #[Test]
    public function adminTogglesActiveState(): void
    {
        $entry = new AbsenceType(
            $this->company,
            'Sabbatical',
            true,
            true,
            '#A855F7',
        );
        $this->em->persist($entry);
        $this->em->flush();
        $id = $entry->getId();
        self::assertTrue($entry->isActive());

        $this->loginAs('admin@leaveflow.test');

        // Extract CSRF token from the rendered toggle form (session-backed).
        $crawler = $this->client->request('GET', '/admin/absence-types');
        $token1 = $crawler->filter('form[action$="/toggle"] input[name="_token"]')
            ->first()
            ->attr('value');
        self::assertNotNull($token1);

        $this->client->request(
            'POST',
            '/admin/absence-types/'.$id.'/toggle',
            ['_token' => $token1],
        );

        self::assertResponseRedirects('/admin/absence-types');

        $this->em->clear();
        /** @var AbsenceType $refreshed */
        $refreshed = $this->em->getRepository(AbsenceType::class)->find($id);
        self::assertFalse($refreshed->isActive());

        // Toggle again → should reactivate.
        $crawler = $this->client->request('GET', '/admin/absence-types');
        $token2 = $crawler->filter('form[action$="/toggle"] input[name="_token"]')
            ->first()
            ->attr('value');
        self::assertNotNull($token2);

        $this->client->request(
            'POST',
            '/admin/absence-types/'.$id.'/toggle',
            ['_token' => $token2],
        );

        $this->em->clear();
        /** @var AbsenceType $refreshed2 */
        $refreshed2 = $this->em->getRepository(AbsenceType::class)->find($id);
        self::assertTrue($refreshed2->isActive());
    }

    #[Test]
    public function managerIsForbidden(): void
    {
        $this->loginAs('manager@leaveflow.test');

        $this->client->request('GET', '/admin/absence-types');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

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
