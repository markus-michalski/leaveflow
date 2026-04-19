<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use App\Domain\Entity\Employee;
use App\Domain\Entity\HolidayOverride;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public const DEFAULT_PASSWORD = 'leaveflow-dev';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $company = new Company('Acme GmbH', 36);
        $manager->persist($company);

        $hq = new Location($company, 'Hauptsitz München', 'DE', 'DE-BY', 'München');
        $branchBerlin = new Location($company, 'Standort Berlin', 'DE', 'DE-BE', 'Berlin');
        $manager->persist($hq);
        $manager->persist($branchBerlin);

        $users = [];
        foreach ($this->userSeeds() as [$email, $role]) {
            $user = new User($company, $email, $role);
            $user->setHashedPassword(
                $this->passwordHasher->hashPassword($user, self::DEFAULT_PASSWORD)
            );
            $manager->persist($user);
            $users[$email] = $user;
        }

        // Admin stays user-only (external IT account — no HR record).
        // Manager + Employee get full employee profiles.
        $manager->persist(new Employee(
            $company,
            'Maya Manager',
            'EMP-0001',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-15'),
            $users['manager@leaveflow.test'],
        ));

        $manager->persist(new Employee(
            $company,
            'Erik Employee',
            'EMP-0002',
            $branchBerlin,
            WorkSchedule::autoDistribute(30.0, [
                Weekday::Monday,
                Weekday::Tuesday,
                Weekday::Wednesday,
                Weekday::Thursday,
            ]),
            new \DateTimeImmutable('2025-03-01'),
            $users['employee@leaveflow.test'],
        ));

        // Demonstrates Employee without User (pre-go-live import / archived ex-employee).
        $manager->persist(new Employee(
            $company,
            'Hannah History',
            'EMP-0003',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2019-05-01'),
            null,
            new \DateTimeImmutable('2024-09-30'),
        ));

        // Phase 3: demo holiday configuration for the current + next year.
        $currentYear = (int) new \DateTimeImmutable()->format('Y');
        foreach ([$currentYear, $currentYear + 1] as $year) {
            // Augsburger Friedensfest (added) — demo only for Bayern.
            $manager->persist(new HolidayOverride(
                $company,
                FederalState::Bayern,
                new \DateTimeImmutable()->setDate($year, 8, 8)->setTime(0, 0),
                'Augsburger Hohes Friedensfest',
                HolidayOverrideType::Added,
            ));

            // Brueckentag after Tag der Deutschen Einheit (Friday if 3.10. is Thursday; pragmatic: skip if weekend).
            $tdde = new \DateTimeImmutable()->setDate($year, 10, 3)->setTime(0, 0);
            if ('Thursday' === $tdde->format('l')) {
                $manager->persist(new CompanyHoliday(
                    $company,
                    $tdde->modify('+1 day'),
                    'Brückentag nach Tag der Deutschen Einheit',
                ));
            }

            // Company-wide Betriebsruhe between Christmas and New Year (Dec 27-31).
            for ($day = 27; $day <= 31; ++$day) {
                $date = new \DateTimeImmutable()->setDate($year, 12, $day)->setTime(0, 0);
                if ('Saturday' === $date->format('l') || 'Sunday' === $date->format('l')) {
                    continue;
                }
                $manager->persist(new CompanyHoliday(
                    $company,
                    $date,
                    'Betriebsruhe Weihnachten',
                ));
            }
        }

        $manager->flush();
    }

    /**
     * @return iterable<array{0: string, 1: UserRole}>
     */
    private function userSeeds(): iterable
    {
        yield ['admin@leaveflow.test', UserRole::Admin];
        yield ['manager@leaveflow.test', UserRole::Manager];
        yield ['employee@leaveflow.test', UserRole::Employee];
    }
}
