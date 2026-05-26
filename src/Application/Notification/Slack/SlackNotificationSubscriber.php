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

use App\Application\Security\EncryptionServiceInterface;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Event\LeaveRequestSubmittedEvent;
use App\Domain\Repository\CompanyRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Dispatches Slack Block Kit notifications for leave request events.
 *
 * Listens on two channels:
 *   - LeaveRequestSubmittedEvent: posts a pending request card with Approve/Reject buttons
 *   - workflow.leave_request_approval.completed: posts a decision card + DMs the employee
 *
 * Errors are swallowed by SlackNotifier — a failing Slack integration must
 * never block the primary leave-request workflow.
 */
final readonly class SlackNotificationSubscriber
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private SlackNotifierInterface $notifier,
        private SlackBlockBuilder $blockBuilder,
        private EncryptionServiceInterface $encryption,
    ) {
    }

    #[AsEventListener(event: LeaveRequestSubmittedEvent::class)]
    public function onLeaveRequestSubmitted(LeaveRequestSubmittedEvent $event): void
    {
        [$botToken, $channelId] = $this->resolveCredentials();
        if (null === $botToken || null === $channelId) {
            return;
        }

        $request = $event->leaveRequest;
        $blocks = $this->blockBuilder->buildPendingRequestBlocks($request);
        $text = "Neuer Urlaubsantrag von {$request->getEmployee()->getFullName()}";

        $this->notifier->postMessage($botToken, $channelId, $blocks, $text);
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

        [$botToken, $channelId] = $this->resolveCredentials();
        if (null === $botToken || null === $channelId) {
            return;
        }

        $context = $event->getContext();
        $actor = $context['actor'] ?? null;
        $decidedBy = $actor instanceof Employee ? $actor->getFullName() : '';
        $decision = 'approve' === $transitionName ? 'approved' : 'rejected';

        $blocks = $this->blockBuilder->buildDecisionBlocks($request, $decision, $decidedBy);
        $text = ('approved' === $decision ? 'Genehmigt' : 'Abgelehnt').": {$request->getEmployee()->getFullName()}";
        $this->notifier->postMessage($botToken, $channelId, $blocks, $text);

        $this->dmEmployee($request, $decision, $botToken);
    }

    private function dmEmployee(LeaveRequest $request, string $decision, string $botToken): void
    {
        $user = $request->getEmployee()->getUser();
        if (null === $user || null === $user->getSlackUserId()) {
            return;
        }

        $dmChannel = $this->notifier->openDm($botToken, $user->getSlackUserId());
        if (null === $dmChannel || '' === $dmChannel) {
            return;
        }

        $blocks = $this->blockBuilder->buildEmployeeDmBlocks($request, $decision);
        $statusText = 'approved' === $decision ? 'genehmigt' : 'abgelehnt';
        $this->notifier->postMessage($botToken, $dmChannel, $blocks, "Dein Urlaubsantrag wurde {$statusText}.");
    }

    /**
     * @return array{?string, ?string}
     */
    private function resolveCredentials(): array
    {
        $company = $this->companyRepository->findOneBy([]);
        if (null === $company || !$company->isSlackEnabled()) {
            return [null, null];
        }

        $encryptedToken = $company->getSlackBotToken();
        $channelId = $company->getSlackChannelId();

        if (null === $encryptedToken || null === $channelId) {
            return [null, null];
        }

        $botToken = $this->encryption->tryDecrypt($encryptedToken);
        if (null === $botToken) {
            return [null, null];
        }

        return [$botToken, $channelId];
    }
}
