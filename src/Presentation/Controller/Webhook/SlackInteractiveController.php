<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Webhook;

use App\Application\Approval\ApprovalWorkflow;
use App\Application\Approval\InvalidTransitionException;
use App\Application\Leave\InsufficientLeaveBalanceException;
use App\Application\Leave\LeaveRequestService;
use App\Application\Notification\Slack\SlackNotifierInterface;
use App\Application\Notification\Slack\SlackUserResolver;
use App\Application\Security\EncryptionServiceInterface;
use App\Application\Security\SlackSignatureVerifier;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\AbsenceTypeRepository;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\LeaveRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles Slack interactive component payloads.
 *
 * Two payload types are processed:
 *   block_actions    — Approve / Reject button clicks on leave-request cards
 *   view_submission  — Modal form submission from the /urlaub slash command
 *
 * All requests are HMAC-verified before any business logic runs.
 * Responses must be delivered within Slack's 3-second timeout.
 */
#[Route('/webhook/slack')]
final class SlackInteractiveController extends AbstractController
{
    private const string DEFAULT_REJECT_REASON = 'Abgelehnt über Slack';

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly SlackSignatureVerifier $signatureVerifier,
        private readonly EncryptionServiceInterface $encryption,
        private readonly SlackUserResolver $userResolver,
        private readonly SlackNotifierInterface $notifier,
        private readonly ApprovalWorkflow $approvalWorkflow,
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly AbsenceTypeRepository $absenceTypeRepository,
        private readonly LeaveRequestService $leaveRequestService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/interactive', name: 'app_slack_interactive', methods: ['POST'])]
    public function interactive(Request $request): Response
    {
        $signingSecret = $this->resolveSigningSecret();
        if (null === $signingSecret) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$this->signatureVerifier->verify($request, $signingSecret)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $raw = (string) $request->request->get('payload', '');
        if ('' === $raw) {
            return new JsonResponse(['error' => 'missing payload'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($raw, associative: true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $type = (string) ($payload['type'] ?? '');

        return match ($type) {
            'block_actions' => $this->handleBlockActions($payload),
            'view_submission' => $this->handleViewSubmission($payload),
            default => new JsonResponse([]),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleBlockActions(array $payload): JsonResponse
    {
        $actions = (array) ($payload['actions'] ?? []);
        $responseUrl = (string) ($payload['response_url'] ?? '');
        /** @var array<string, mixed> $slackUser */
        $slackUser = (array) ($payload['user'] ?? []);
        $slackUserId = (string) ($slackUser['id'] ?? '');

        $actor = $this->userResolver->resolveEmployee($slackUserId);
        if (null === $actor) {
            return new JsonResponse([
                'response_type' => 'ephemeral',
                'text' => 'Dein Slack-Account ist nicht mit LeaveFlow verknüpft. Bitte wende dich an deinen Admin.',
                'replace_original' => false,
            ]);
        }

        foreach ($actions as $action) {
            /** @var array<string, mixed> $action */
            $actionId = (string) ($action['action_id'] ?? '');
            [$actionName, $requestId] = $this->parseActionId($actionId);

            if (null === $requestId) {
                continue;
            }

            $leaveRequest = $this->leaveRequestRepository->find($requestId);
            if (null === $leaveRequest) {
                continue;
            }

            if (!$this->isActorAuthorizedToDecide($actor, $leaveRequest)) {
                if ('' !== $responseUrl) {
                    $this->notifier->sendResponseUrl($responseUrl, [
                        'replace_original' => false,
                        'response_type' => 'ephemeral',
                        'text' => 'Du bist nicht berechtigt, über diesen Antrag zu entscheiden.',
                    ]);
                }
                continue;
            }

            try {
                if ('approve' === $actionName) {
                    $this->approvalWorkflow->approve($leaveRequest, $actor);
                } elseif ('reject' === $actionName) {
                    $this->approvalWorkflow->reject($leaveRequest, $actor, self::DEFAULT_REJECT_REASON);
                } else {
                    continue;
                }

                $this->entityManager->flush();

                if ('' !== $responseUrl) {
                    $statusText = 'approve' === $actionName ? 'Genehmigt ✓' : 'Abgelehnt ✗';
                    $this->notifier->sendResponseUrl($responseUrl, [
                        'replace_original' => true,
                        'text' => "{$statusText} von {$actor->getFullName()}",
                    ]);
                }
            } catch (InvalidTransitionException) {
                if ('' !== $responseUrl) {
                    $this->notifier->sendResponseUrl($responseUrl, [
                        'replace_original' => false,
                        'response_type' => 'ephemeral',
                        'text' => 'Dieser Antrag kann nicht mehr geändert werden.',
                    ]);
                }
            }
        }

        return new JsonResponse([]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleViewSubmission(array $payload): JsonResponse
    {
        $callbackId = (string) (((array) ($payload['view'] ?? []))['callback_id'] ?? '');

        if ('leave_request_submit' !== $callbackId) {
            return new JsonResponse([]);
        }

        /** @var array<string, mixed> $slackUser */
        $slackUser = (array) ($payload['user'] ?? []);
        $slackUserId = (string) ($slackUser['id'] ?? '');

        $employee = $this->userResolver->resolveEmployee($slackUserId);
        if (null === $employee) {
            return new JsonResponse([
                'response_action' => 'errors',
                'errors' => [
                    'absence_type' => 'Dein Slack-Account ist nicht mit LeaveFlow verknüpft.',
                ],
            ]);
        }

        /** @var array<string, mixed> $view */
        $view = (array) ($payload['view'] ?? []);
        /** @var array<string, mixed> $state */
        $state = (array) ($view['state'] ?? []);
        /** @var array<string, array<string, mixed>> $values */
        $values = (array) ($state['values'] ?? []);

        $absenceTypeId = (int) $this->readSelectedOption($values, 'absence_type');
        $startDateStr = $this->readSelectedDate($values, 'start_date') ?? '';
        $endDateStr = $this->readSelectedDate($values, 'end_date') ?? '';

        if (0 === $absenceTypeId || '' === $startDateStr || '' === $endDateStr) {
            return new JsonResponse([
                'response_action' => 'errors',
                'errors' => ['absence_type' => 'Bitte alle Felder ausfüllen.'],
            ]);
        }

        $absenceType = $this->absenceTypeRepository->find($absenceTypeId);
        if (null === $absenceType || $absenceType->getCompany() !== $employee->getCompany()) {
            return new JsonResponse([
                'response_action' => 'errors',
                'errors' => ['absence_type' => 'Ungültige Abwesenheitsart.'],
            ]);
        }

        try {
            $startDate = new \DateTimeImmutable($startDateStr);
            $endDate = new \DateTimeImmutable($endDateStr);
        } catch (\Throwable) {
            return new JsonResponse([
                'response_action' => 'errors',
                'errors' => ['start_date' => 'Ungültiges Datumsformat.'],
            ]);
        }

        if ($endDate < $startDate) {
            return new JsonResponse([
                'response_action' => 'errors',
                'errors' => ['end_date' => 'Das Enddatum darf nicht vor dem Startdatum liegen.'],
            ]);
        }

        try {
            $this->leaveRequestService->create($employee, $absenceType, $startDate, $endDate, LeaveDayType::FullDay);
        } catch (InsufficientLeaveBalanceException) {
            return new JsonResponse([
                'response_action' => 'errors',
                'errors' => ['start_date' => 'Nicht genügend Urlaubstage verfügbar.'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'response_action' => 'errors',
                'errors' => ['start_date' => $e->getMessage()],
            ]);
        }

        return new JsonResponse(['response_action' => 'clear']);
    }

    /**
     * @return array{?string, ?int}
     */
    private function parseActionId(string $actionId): array
    {
        $parts = explode(':', $actionId, 2);
        if (2 !== \count($parts)) {
            return [null, null];
        }

        $id = (int) $parts[1];
        if ($id <= 0) {
            return [null, null];
        }

        return [$parts[0], $id];
    }

    /**
     * Mirrors the rules from LeaveRequestApprovalVoter for the Slack context
     * where no security session/token exists (auth is the HMAC signature).
     *
     * Rules:
     *   - Self-approval is always denied (four-eyes principle)
     *   - Admins may act on any request
     *   - Otherwise the actor must be the department lead or deputy
     */
    private function isActorAuthorizedToDecide(Employee $actor, LeaveRequest $leaveRequest): bool
    {
        if ($actor === $leaveRequest->getEmployee()) {
            return false;
        }

        $user = $actor->getUser();
        if (null !== $user && UserRole::Admin === $user->getRole()) {
            return true;
        }

        $department = $leaveRequest->getEmployee()->getDepartment();
        if (null === $department || !$department->isActive()) {
            return false;
        }

        return $actor === $department->getLead() || $actor === $department->getDeputy();
    }

    /**
     * Safely reads a datepicker value from Slack's view.state.values structure.
     * Structure: values[block_id][action_id] = {type: 'datepicker', selected_date: '…'}
     *
     * @param array<string, mixed> $values
     */
    private function readSelectedDate(array $values, string $blockId): ?string
    {
        $block = $values[$blockId] ?? null;
        if (!\is_array($block)) {
            return null;
        }
        $input = $block['value'] ?? null;
        if (!\is_array($input)) {
            return null;
        }
        $date = $input['selected_date'] ?? null;

        return \is_string($date) && '' !== $date ? $date : null;
    }

    /**
     * Safely reads a static_select value from Slack's view.state.values structure.
     * Structure: values[block_id][action_id] = {type: 'static_select', selected_option: {value: '…'}}
     *
     * @param array<string, mixed> $values
     */
    private function readSelectedOption(array $values, string $blockId): ?string
    {
        $block = $values[$blockId] ?? null;
        if (!\is_array($block)) {
            return null;
        }
        $input = $block['value'] ?? null;
        if (!\is_array($input)) {
            return null;
        }
        $option = $input['selected_option'] ?? null;
        if (!\is_array($option)) {
            return null;
        }
        $value = $option['value'] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function resolveSigningSecret(): ?string
    {
        $company = $this->companyRepository->findOneBy([]);
        if (null === $company || !$company->isSlackEnabled()) {
            return null;
        }

        $encryptedSecret = $company->getSlackSigningSecret();
        if (null === $encryptedSecret) {
            return null;
        }

        return $this->encryption->tryDecrypt($encryptedSecret);
    }
}
