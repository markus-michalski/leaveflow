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

namespace App\Presentation\Controller\Webhook;

use App\Application\Notification\Slack\SlackBlockBuilder;
use App\Application\Notification\Slack\SlackNotifierInterface;
use App\Application\Notification\Slack\SlackUserResolver;
use App\Application\Security\EncryptionServiceInterface;
use App\Application\Security\SlackSignatureVerifier;
use App\Domain\Repository\AbsenceTypeRepository;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\LeaveRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles Slack slash command requests.
 *
 * Every incoming request is verified with HMAC-SHA256 (X-Slack-Signature).
 * Responses must be delivered within Slack's 3-second timeout.
 *
 * Registered slash commands:
 *   /urlaub [request]  — create a leave request via modal or show help
 *   /team-abwesend     — list who is absent today
 */
#[Route('/webhook/slack')]
final class SlackWebhookController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly SlackSignatureVerifier $signatureVerifier,
        private readonly EncryptionServiceInterface $encryption,
        private readonly SlackUserResolver $userResolver,
        private readonly SlackNotifierInterface $notifier,
        private readonly SlackBlockBuilder $blockBuilder,
        private readonly AbsenceTypeRepository $absenceTypeRepository,
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/commands', name: 'app_slack_commands', methods: ['POST'])]
    public function commands(Request $request): Response
    {
        [$botToken, $signingSecret] = $this->resolveCredentials();
        if (null === $signingSecret) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$this->signatureVerifier->verify($request, $signingSecret)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $command = (string) $request->request->get('command', '');
        $text = trim((string) $request->request->get('text', ''));
        $slackUserId = (string) $request->request->get('user_id', '');
        $triggerId = (string) $request->request->get('trigger_id', '');

        return match ($command) {
            '/urlaub' => $this->handleUrlaub($text, $slackUserId, $triggerId, $botToken),
            '/team-abwesend' => $this->handleTeamAbwesend($slackUserId),
            default => $this->ephemeral('Unbekannter Befehl.'),
        };
    }

    private function handleUrlaub(string $text, string $slackUserId, string $triggerId, ?string $botToken): JsonResponse
    {
        if ('request' !== strtolower($text) && '' !== $text) {
            return $this->ephemeral("Verfügbare Befehle:\n• `/urlaub request` — Urlaubsantrag erstellen");
        }

        $employee = $this->userResolver->resolveEmployee($slackUserId);
        if (null === $employee) {
            return $this->ephemeral('Dein Slack-Account ist nicht mit LeaveFlow verknüpft. Bitte wende dich an deinen Admin.');
        }

        if (null === $botToken) {
            return $this->ephemeral('Slack-Integration ist nicht vollständig konfiguriert.');
        }

        $company = $employee->getCompany();
        $absenceTypes = $this->absenceTypeRepository->findActiveByCompany($company);

        $options = array_map(
            static fn ($type) => [
                'text' => ['type' => 'plain_text', 'text' => $type->getName(), 'emoji' => true],
                'value' => (string) $type->getId(),
            ],
            $absenceTypes,
        );

        if ([] === $options) {
            return $this->ephemeral('Keine Abwesenheitsarten verfügbar.');
        }

        $modal = $this->blockBuilder->buildLeaveRequestModal($options);
        $this->notifier->openModal($botToken, $triggerId, $modal);

        return new JsonResponse([]);
    }

    private function handleTeamAbwesend(string $slackUserId): JsonResponse
    {
        $employee = $this->userResolver->resolveEmployee($slackUserId);
        if (null === $employee) {
            return $this->ephemeral('Dein Slack-Account ist nicht mit LeaveFlow verknüpft. Bitte wende dich an deinen Admin.');
        }

        $today = \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $absences = $this->leaveRequestRepository->findActiveAbsencesOn($employee->getCompany(), $today, 25);

        if ([] === $absences) {
            return new JsonResponse([
                'response_type' => 'ephemeral',
                'text' => 'Heute ist niemand abwesend. :tada:',
            ]);
        }

        $lines = array_map(
            static fn ($r) => "• {$r->getEmployee()->getFullName()} — {$r->getAbsenceType()->getName()}",
            $absences,
        );

        return new JsonResponse([
            'response_type' => 'ephemeral',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => "Abwesend heute ({$today->format('d.m.Y')})", 'emoji' => true],
                ],
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)],
                ],
            ],
        ]);
    }

    private function ephemeral(string $text): JsonResponse
    {
        return new JsonResponse(['response_type' => 'ephemeral', 'text' => $text]);
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
        $encryptedSecret = $company->getSlackSigningSecret();

        $botToken = null !== $encryptedToken ? $this->encryption->tryDecrypt($encryptedToken) : null;
        $signingSecret = null !== $encryptedSecret ? $this->encryption->tryDecrypt($encryptedSecret) : null;

        return [$botToken, $signingSecret];
    }
}
