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

namespace App\Tests\Unit\Application\Security;

use App\Application\Security\SlackSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(SlackSignatureVerifier::class)]
class SlackSignatureVerifierTest extends TestCase
{
    private SlackSignatureVerifier $verifier;
    private string $secret;

    protected function setUp(): void
    {
        $this->verifier = new SlackSignatureVerifier();
        $this->secret = 'test-signing-secret-abc123';
    }

    private function buildRequest(string $body, string $timestamp, string $secret): Request
    {
        $sigBase = "v0:{$timestamp}:{$body}";
        $sig = 'v0='.hash_hmac('sha256', $sigBase, $secret);

        $request = Request::create('/webhook/slack/commands', 'POST', content: $body);
        $request->headers->set('X-Slack-Request-Timestamp', $timestamp);
        $request->headers->set('X-Slack-Signature', $sig);

        return $request;
    }

    #[Test]
    public function acceptsValidSignature(): void
    {
        $timestamp = (string) time();
        $request = $this->buildRequest('command=/urlaub&text=request', $timestamp, $this->secret);

        self::assertTrue($this->verifier->verify($request, $this->secret));
    }

    #[Test]
    public function rejectsWrongSignature(): void
    {
        $timestamp = (string) time();
        $request = $this->buildRequest('command=/urlaub', $timestamp, $this->secret);
        $request->headers->set('X-Slack-Signature', 'v0=invalidsignature');

        self::assertFalse($this->verifier->verify($request, $this->secret));
    }

    #[Test]
    public function rejectsExpiredTimestamp(): void
    {
        $oldTimestamp = (string) (time() - 400);
        $request = $this->buildRequest('command=/urlaub', $oldTimestamp, $this->secret);

        self::assertFalse($this->verifier->verify($request, $this->secret));
    }

    #[Test]
    public function rejectsMissingTimestampHeader(): void
    {
        $request = Request::create('/webhook/slack/commands', 'POST');
        $request->headers->set('X-Slack-Signature', 'v0=abc');

        self::assertFalse($this->verifier->verify($request, $this->secret));
    }

    #[Test]
    public function rejectsMissingSignatureHeader(): void
    {
        $request = Request::create('/webhook/slack/commands', 'POST');
        $request->headers->set('X-Slack-Request-Timestamp', (string) time());

        self::assertFalse($this->verifier->verify($request, $this->secret));
    }

    #[Test]
    public function rejectsNonNumericTimestamp(): void
    {
        $request = Request::create('/webhook/slack/commands', 'POST');
        $request->headers->set('X-Slack-Request-Timestamp', 'not-a-number');
        $request->headers->set('X-Slack-Signature', 'v0=abc');

        self::assertFalse($this->verifier->verify($request, $this->secret));
    }

    #[Test]
    public function acceptsRequestAtEdgeOfReplayWindow(): void
    {
        $timestamp = (string) (time() - 299);
        $request = $this->buildRequest('body=test', $timestamp, $this->secret);

        self::assertTrue($this->verifier->verify($request, $this->secret));
    }
}
