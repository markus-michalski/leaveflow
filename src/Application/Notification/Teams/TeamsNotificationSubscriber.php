<?php

declare(strict_types=1);

namespace App\Application\Notification\Teams;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Event\LeaveRequestSubmittedEvent;
use App\Domain\Repository\CompanyRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Dispatches Microsoft Teams Adaptive Card notifications for leave request events.
 *
 * Listens on two channels:
 *   - LeaveRequestSubmittedEvent: fired by LeaveRequestService after a new Pending request is persisted
 *   - workflow.leave_request_approval.completed: fired by the Symfony Workflow on approve/reject transitions
 *
 * All errors are swallowed by TeamsNotifier — a failing webhook must never
 * block the primary workflow.
 */
final readonly class TeamsNotificationSubscriber
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private TeamsNotifierInterface $notifier,
        private TeamsCardBuilder $cardBuilder,
    ) {
    }

    #[AsEventListener(event: LeaveRequestSubmittedEvent::class)]
    public function onLeaveRequestSubmitted(LeaveRequestSubmittedEvent $event): void
    {
        $webhookUrl = $this->resolveWebhookUrl();
        if (null === $webhookUrl) {
            return;
        }

        $card = $this->cardBuilder->buildPendingRequestCard($event->leaveRequest);
        $this->notifier->send($webhookUrl, $card);
    }

    #[AsEventListener(event: 'workflow.leave_request_approval.completed')]
    public function onWorkflowCompleted(CompletedEvent $event): void
    {
        $request = $event->getSubject();
        if (!$request instanceof LeaveRequest) {
            return;
        }

        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        $transitionName = $transition->getName();
        if (!\in_array($transitionName, ['approve', 'reject'], true)) {
            return;
        }

        $webhookUrl = $this->resolveWebhookUrl();
        if (null === $webhookUrl) {
            return;
        }

        $context = $event->getContext();
        $actor = $context['actor'] ?? null;
        $decidedBy = $actor instanceof Employee ? $actor->getFullName() : '';
        $decision = 'approve' === $transitionName ? 'approved' : 'rejected';

        $card = $this->cardBuilder->buildDecisionCard($request, $decision, $decidedBy);
        $this->notifier->send($webhookUrl, $card);
    }

    private function resolveWebhookUrl(): ?string
    {
        $company = $this->companyRepository->findOneBy([]);
        if (null === $company || !$company->isTeamsEnabled()) {
            return null;
        }

        return $company->getTeamsWebhookUrl();
    }
}
