<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class IcalControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Employee $employee;
    private User $user;
    private Department $engineering;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function unknownTokenReturns404(): void
    {
        $bogus = str_repeat('a', 64);
        $this->client->request('GET', '/ical/personal/'.$bogus.'.ics');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function malformedTokenLengthIsRejectedByRouter(): void
    {
        // Route only matches 64-char hex, so wrong-length tokens never reach the controller.
        $this->client->request('GET', '/ical/personal/short.ics');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function personalFeedReturnsCalendarForOwnAbsences(): void
    {
        $vacation = $this->seedVacationType();
        $this->seedApprovedRequest($this->employee, $vacation, '2026-07-06', '2026-07-10');
        $token = $this->user->ensureIcalToken();
        $this->em->flush();

        $this->client->request('GET', '/ical/personal/'.$token.'.ics');

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('text/calendar', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
        self::assertStringContainsString('SUMMARY:Urlaub', $body);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20260706', $body);
    }

    #[Test]
    public function personalFeedFor404UserWithoutEmployee(): void
    {
        // Detach the User from any Employee record — admin-only user case.
        $admin = new User($this->company, 'admin-only@leaveflow.test', UserRole::Admin);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $admin->setHashedPassword($hasher->hashPassword($admin, AppFixtures::DEFAULT_PASSWORD));
        $token = $admin->ensureIcalToken();
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->request('GET', '/ical/personal/'.$token.'.ics');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function inactiveUserCannotUseToken(): void
    {
        $this->user->deactivate();
        $token = $this->user->ensureIcalToken();
        $this->em->flush();

        $this->client->request('GET', '/ical/personal/'.$token.'.ics');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function teamFeedIncludesEmployeeNameInSummary(): void
    {
        $vacation = $this->seedVacationType();
        $this->seedApprovedRequest($this->employee, $vacation, '2026-07-06', '2026-07-10');
        $token = $this->user->ensureIcalToken();
        $this->em->flush();

        $this->client->request('GET', '/ical/team/'.$token.'.ics');

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('Erik Employee', $body);
    }

    #[Test]
    public function teamFeedReturns404WhenEmployeeHasNoDepartment(): void
    {
        $this->employee->assignToDepartment(null);
        $token = $this->user->ensureIcalToken();
        $this->em->flush();

        $this->client->request('GET', '/ical/team/'.$token.'.ics');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH');
        $hq = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');

        $this->employee = new Employee(
            $this->company,
            'Erik Employee',
            'EMP-1',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2025-01-01'),
        );

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User($this->company, 'erik@leaveflow.test', UserRole::Employee);
        $this->user->setHashedPassword($hasher->hashPassword($this->user, AppFixtures::DEFAULT_PASSWORD));
        $this->employee->linkUser($this->user);

        $this->engineering = new Department($this->company, 'Engineering');
        $this->employee->assignToDepartment($this->engineering);

        $this->em->persist($this->company);
        $this->em->persist($hq);
        $this->em->persist($this->user);
        $this->em->persist($this->employee);
        $this->em->persist($this->engineering);
        $this->em->flush();
    }

    private function seedVacationType(): AbsenceType
    {
        $vacation = new AbsenceType(
            $this->company,
            'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->em->persist($vacation);
        $this->em->flush();

        return $vacation;
    }

    private function seedApprovedRequest(
        Employee $employee,
        AbsenceType $type,
        string $start,
        string $end,
    ): LeaveRequest {
        $startDt = new \DateTimeImmutable($start);
        $endDt = new \DateTimeImmutable($end);
        $request = new LeaveRequest(
            $employee,
            $type,
            $startDt,
            $endDt,
            LeaveDayType::FullDay,
            $startDt->modify('-1 day')->setTime(9, 0),
        );
        $days = [];
        $cursor = $startDt;
        while ($cursor <= $endDt) {
            $w = Weekday::fromDateTime($cursor);
            if (\in_array($w, [Weekday::Saturday, Weekday::Sunday], true)) {
                $days[] = new LeaveDay($cursor, 0.0, LeaveDayStatus::Excluded, \App\Domain\Enum\ExclusionReason::Weekend);
            } else {
                $days[] = new LeaveDay($cursor, 8.0, LeaveDayStatus::Working);
            }
            $cursor = $cursor->modify('+1 day');
        }
        $request->applyBreakdown(new LeaveBreakdown($days));
        $request->setStatus(LeaveRequestStatus::Approved);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }
}
