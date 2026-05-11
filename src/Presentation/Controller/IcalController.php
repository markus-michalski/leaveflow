<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Ical\IcalFeedBuilder;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public iCal feed endpoints. Authenticated by URL token, not by
 * session cookie — calendar clients (Google, Outlook, Apple) don't
 * forward cookies on subscription refresh requests.
 *
 * 404 for every failure mode (unknown token, inactive user, missing
 * employee record, missing department for the team feed) so token
 * enumeration attempts can't distinguish "wrong token" from
 * "right token, wrong role" — same exposure as a private gist URL.
 */
final class IcalController extends AbstractController
{
    private const string FEED_WINDOW_PAST_MONTHS = '-3 months';
    private const string FEED_WINDOW_FUTURE_MONTHS = '+12 months';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LeaveRequestRepository $requestRepository,
        private readonly IcalFeedBuilder $builder,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        '/ical/personal/{token}.ics',
        name: 'app_ical_personal',
        requirements: ['token' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function personal(string $token): Response
    {
        $user = $this->userRepository->findOneActiveByIcalToken($token);
        if (null === $user) {
            throw $this->createNotFoundException();
        }
        $employee = $user->getEmployee();
        if (null === $employee) {
            throw $this->createNotFoundException();
        }

        [$rangeStart, $rangeEnd] = $this->buildRange();
        $requests = $this->requestRepository->findAbsencesForEmployeeInRange(
            $employee,
            $rangeStart,
            $rangeEnd,
        );

        return $this->makeIcsResponse(
            $this->builder->buildPersonalFeed($requests),
            'leaveflow-personal.ics',
        );
    }

    #[Route(
        '/ical/team/{token}.ics',
        name: 'app_ical_team',
        requirements: ['token' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function team(string $token): Response
    {
        $user = $this->userRepository->findOneActiveByIcalToken($token);
        if (null === $user) {
            throw $this->createNotFoundException();
        }
        $employee = $user->getEmployee();
        if (null === $employee) {
            throw $this->createNotFoundException();
        }
        $department = $employee->getDepartment();
        if (null === $department) {
            throw $this->createNotFoundException();
        }

        [$rangeStart, $rangeEnd] = $this->buildRange();
        $requests = $this->requestRepository->findApprovedOverlapping(
            $user->getCompany(),
            $rangeStart,
            $rangeEnd,
            $department,
        );

        return $this->makeIcsResponse(
            $this->builder->buildTeamFeed($requests),
            'leaveflow-team.ics',
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function buildRange(): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);

        return [
            $now->modify(self::FEED_WINDOW_PAST_MONTHS),
            $now->modify(self::FEED_WINDOW_FUTURE_MONTHS),
        ];
    }

    private function makeIcsResponse(string $ics, string $filename): Response
    {
        return new Response(
            $ics,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/calendar; charset=UTF-8',
                'Content-Disposition' => \sprintf('inline; filename="%s"', $filename),
                // Calendar clients hammer the URL on their own schedule;
                // a short revalidation window is enough to absorb manual
                // refreshes without making the source-of-truth feel stale.
                'Cache-Control' => 'private, max-age=300',
            ],
        );
    }
}
