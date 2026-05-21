<?php

declare(strict_types=1);

namespace App\Application\Notification\Slack;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts messages to Slack via the Web API.
 *
 * All errors are logged and swallowed — a failing Slack integration must
 * never block the primary leave-request workflow.
 */
final readonly class SlackNotifier implements SlackNotifierInterface
{
    private const string API_BASE = 'https://slack.com/api/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    public function postMessage(string $botToken, string $channelId, array $blocks, string $text = ''): ?string
    {
        try {
            $response = $this->httpClient->request('POST', self::API_BASE.'chat.postMessage', [
                'headers' => ['Authorization' => "Bearer {$botToken}"],
                'json' => ['channel' => $channelId, 'text' => $text, 'blocks' => $blocks],
                'timeout' => 3.0,
                'max_duration' => 5.0,
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray(throw: false);
            if (!($data['ok'] ?? false)) {
                $this->logger->warning('Slack postMessage failed', ['error' => $data['error'] ?? 'unknown']);

                return null;
            }

            return (string) ($data['ts'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->error('Slack postMessage exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendResponseUrl(string $responseUrl, array $payload): void
    {
        try {
            $response = $this->httpClient->request('POST', $responseUrl, [
                'json' => $payload,
                'timeout' => 3.0,
                'max_duration' => 5.0,
            ]);

            if ($response->getStatusCode() >= 300) {
                $this->logger->warning('Slack response_url returned non-2xx', [
                    'status' => $response->getStatusCode(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Slack sendResponseUrl exception', ['error' => $e->getMessage()]);
        }
    }

    public function openDm(string $botToken, string $slackUserId): ?string
    {
        try {
            $response = $this->httpClient->request('POST', self::API_BASE.'conversations.open', [
                'headers' => ['Authorization' => "Bearer {$botToken}"],
                'json' => ['users' => $slackUserId],
                'timeout' => 3.0,
                'max_duration' => 5.0,
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray(throw: false);
            if (!($data['ok'] ?? false)) {
                $this->logger->warning('Slack conversations.open failed', ['error' => $data['error'] ?? 'unknown']);

                return null;
            }

            /** @var array<string, mixed> $channel */
            $channel = $data['channel'] ?? [];

            return (string) ($channel['id'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->error('Slack openDm exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $view
     */
    public function openModal(string $botToken, string $triggerId, array $view): void
    {
        try {
            $response = $this->httpClient->request('POST', self::API_BASE.'views.open', [
                'headers' => ['Authorization' => "Bearer {$botToken}"],
                'json' => ['trigger_id' => $triggerId, 'view' => $view],
                'timeout' => 3.0,
                'max_duration' => 5.0,
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray(throw: false);
            if (!($data['ok'] ?? false)) {
                $this->logger->warning('Slack views.open failed', ['error' => $data['error'] ?? 'unknown']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Slack openModal exception', ['error' => $e->getMessage()]);
        }
    }
}
