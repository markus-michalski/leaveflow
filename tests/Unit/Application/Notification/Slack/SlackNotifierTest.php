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

namespace App\Tests\Unit\Application\Notification\Slack;

use App\Application\Notification\Slack\SlackNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(SlackNotifier::class)]
class SlackNotifierTest extends TestCase
{
    private function makeNotifier(HttpClientInterface $client, LoggerInterface $logger): SlackNotifier
    {
        return new SlackNotifier($client, $logger);
    }

    #[Test]
    public function postMessageReturnsTimestampOnSuccess(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(['ok' => true, 'ts' => '1234567890.000100']);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $logger = $this->createStub(LoggerInterface::class);

        $ts = $this->makeNotifier($client, $logger)
            ->postMessage('xoxb-test', 'C0123', [['type' => 'section']], 'text');

        self::assertSame('1234567890.000100', $ts);
    }

    #[Test]
    public function postMessageLogsWarningOnApiError(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(['ok' => false, 'error' => 'channel_not_found']);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $ts = $this->makeNotifier($client, $logger)->postMessage('xoxb-test', 'C_INVALID', [], '');

        self::assertNull($ts);
    }

    #[Test]
    public function postMessageLogsErrorAndReturnsNullOnException(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willThrowException(new \RuntimeException('connection refused'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $ts = $this->makeNotifier($client, $logger)->postMessage('xoxb-test', 'C0123', [], '');

        self::assertNull($ts);
    }

    #[Test]
    public function sendResponseUrlLogsWarningOnNon2xx(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->makeNotifier($client, $logger)
            ->sendResponseUrl('https://hooks.slack.com/actions/test', ['text' => 'ok']);
    }

    #[Test]
    public function sendResponseUrlSwallowsExceptions(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willThrowException(new \RuntimeException('timeout'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->makeNotifier($client, $logger)
            ->sendResponseUrl('https://hooks.slack.com/actions/test', []);
    }

    #[Test]
    public function openDmReturnsChannelIdOnSuccess(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(['ok' => true, 'channel' => ['id' => 'D0123456789']]);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $logger = $this->createStub(LoggerInterface::class);

        $channelId = $this->makeNotifier($client, $logger)->openDm('xoxb-test', 'U0123456789');

        self::assertSame('D0123456789', $channelId);
    }

    #[Test]
    public function openDmReturnsNullOnApiError(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(['ok' => false, 'error' => 'user_not_found']);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        self::assertNull($this->makeNotifier($client, $logger)->openDm('xoxb-test', 'U_INVALID'));
    }
}
