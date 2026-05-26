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

namespace App\Infrastructure\Security;

use App\Application\Approval\LeaveRequestApprovalAttribute;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes manager-side actions on a LeaveRequest: approve, reject, and
 * the two cancel-request decisions (confirm / deny).
 *
 * The Voter only gates who MAY act — the Workflow state machine still decides
 * whether the transition is valid from the current state. That split keeps
 * permission logic out of the state machine and state logic out of Security.
 *
 * Self-approval is blocked regardless of role (four-eyes principle). Admins
 * bypass the department-membership check except when they are the requester
 * themselves.
 *
 * @extends Voter<string, LeaveRequest>
 */
final class LeaveRequestApprovalVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof LeaveRequest && null !== LeaveRequestApprovalAttribute::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $actorEmployee = $user->getEmployee();

        // Four-eyes: anyone acting on their own request is refused, even admins.
        if (null !== $actorEmployee && $actorEmployee === $subject->getEmployee()) {
            return false;
        }

        if (UserRole::Admin === $user->getRole()) {
            return true;
        }

        if (null === $actorEmployee) {
            return false;
        }

        $department = $subject->getEmployee()->getDepartment();
        if (null === $department || !$department->isActive()) {
            return false;
        }

        return $actorEmployee === $department->getLead()
            || $actorEmployee === $department->getDeputy();
    }
}
