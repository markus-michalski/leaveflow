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

namespace App\Application\Approval;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Enum\LeaveRequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Writes a LeaveRequestAuditEntry every time the approval workflow completes
 * a transition.
 *
 * Subscribes to the `completed` event (fires AFTER the subject's marking has
 * been mutated). The from-status is read from the transition definition so we
 * capture the original state even though the subject already reflects the
 * new one.
 *
 * Persist-only: the caller that triggered the workflow (typically a
 * controller or command handler) is responsible for flushing. This fits the
 * UnitOfWork pattern — multiple audit entries written during a single request
 * share one transaction.
 */
#[AsEventListener(event: 'workflow.leave_request_approval.completed')]
final readonly class ApprovalAuditSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof LeaveRequest) {
            return;
        }

        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        // State machines guarantee single from/to per transition — if the
        // invariant were ever broken we'd rather skip silently than write a
        // malformed entry. The workflow dispatcher itself throws on multi-
        // state transitions in a state_machine, so this is defense-in-depth.
        $froms = $transition->getFroms();
        $tos = $transition->getTos();
        if (1 !== \count($froms) || 1 !== \count($tos)) {
            return;
        }

        $context = $event->getContext();
        $actor = $context['actor'] ?? null;
        if (!$actor instanceof Employee) {
            $actor = null;
        }

        $reason = null;
        if (isset($context['reason']) && \is_string($context['reason']) && '' !== $context['reason']) {
            $reason = $context['reason'];
        }

        $entry = new LeaveRequestAuditEntry(
            leaveRequest: $subject,
            actor: $actor,
            transition: $transition->getName(),
            fromStatus: LeaveRequestStatus::from($froms[0]),
            toStatus: LeaveRequestStatus::from($tos[0]),
            occurredAt: $this->clock->now(),
            reason: $reason,
        );

        $this->entityManager->persist($entry);
    }
}
