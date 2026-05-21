<?php

declare(strict_types=1);

namespace App\Application\Notification\Slack;

use App\Domain\Entity\Employee;
use App\Domain\Entity\User;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\UserRepository;

/**
 * Resolves a Slack user_id to the corresponding LeaveFlow User and Employee.
 */
final readonly class SlackUserResolver
{
    public function __construct(
        private UserRepository $userRepository,
        private EmployeeRepository $employeeRepository,
    ) {
    }

    public function resolveUser(string $slackUserId): ?User
    {
        return $this->userRepository->findOneBySlackUserId($slackUserId);
    }

    public function resolveEmployee(string $slackUserId): ?Employee
    {
        $user = $this->resolveUser($slackUserId);
        if (null === $user) {
            return null;
        }

        return $this->employeeRepository->findOneByUser($user);
    }
}
