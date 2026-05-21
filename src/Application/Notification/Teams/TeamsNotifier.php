<?php

declare(strict_types=1);

namespace App\Application\Notification\Teams;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts Adaptive Card payloads to a Microsoft Teams incoming webhook URL.
 *
 * Errors are logged and swallowed so that a misconfigured or unreachable
 * webhook never blocks the primary leave-request workflow.
 */
final readonly class TeamsNotifier implements TeamsNotifierInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $card Adaptive Card JSON payload
     */
    public function send(string $webhookUrl, array $card): void
    {
        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => $card,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 3.0,
                'max_duration' => 5.0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('Teams webhook returned non-2xx response', [
                    'status' => $statusCode,
                    'body' => $response->getContent(throw: false),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Teams notification', [
                'error' => $e->getMessage(),
                'webhook_url_host' => parse_url($webhookUrl, \PHP_URL_HOST),
            ]);
        }
    }
}
