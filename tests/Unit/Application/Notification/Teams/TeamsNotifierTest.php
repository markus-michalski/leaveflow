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

namespace App\Tests\Unit\Application\Notification\Teams;

use App\Application\Notification\Teams\TeamsNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(TeamsNotifier::class)]
class TeamsNotifierTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private TeamsNotifier $notifier;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->notifier = new TeamsNotifier($this->httpClient, $this->logger);
    }

    #[Test]
    public function sendsPostRequestToWebhookUrl(): void
    {
        $card = ['type' => 'message', 'attachments' => []];
        $webhookUrl = 'https://outlook.office.com/webhook/test';

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $webhookUrl, $this->callback(static fn (array $options): bool => isset($options['json']) && isset($options['headers']['Content-Type'])))
            ->willReturn($response);

        $this->logger->expects($this->never())->method('warning');
        $this->logger->expects($this->never())->method('error');

        $this->notifier->send($webhookUrl, $card);
    }

    #[Test]
    public function logsWarningOnNon2xxResponse(): void
    {
        $card = ['type' => 'message'];
        $webhookUrl = 'https://outlook.office.com/webhook/test';

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getContent')->willReturn('Bad request');

        $this->httpClient->expects($this->once())->method('request')->willReturn($response);
        $this->logger->expects($this->once())->method('warning');

        $this->notifier->send($webhookUrl, $card);
    }

    #[Test]
    public function logsErrorAndSwallowsHttpClientException(): void
    {
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->logger->expects($this->once())->method('error');

        $this->notifier->send('https://example.com/webhook', ['type' => 'message']);
    }
}
