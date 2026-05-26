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
