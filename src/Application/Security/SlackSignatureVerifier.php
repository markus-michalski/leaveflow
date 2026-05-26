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

namespace App\Application\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies Slack request authenticity via HMAC-SHA256.
 *
 * Slack signs every incoming request using a shared signing secret:
 *   sig_basestring = "v0:{timestamp}:{raw_body}"
 *   expected_sig   = "v0=" + HMAC-SHA256(signing_secret, sig_basestring)
 *
 * Requests older than 5 minutes are rejected to prevent replay attacks.
 *
 * @see https://api.slack.com/authentication/verifying-requests-from-slack
 */
final readonly class SlackSignatureVerifier
{
    private const int REPLAY_WINDOW_SECONDS = 300;

    public function verify(Request $request, string $signingSecret): bool
    {
        $timestamp = (string) $request->headers->get('X-Slack-Request-Timestamp', '');
        $slackSig = (string) $request->headers->get('X-Slack-Signature', '');

        if ('' === $timestamp || '' === $slackSig) {
            return false;
        }

        if (!ctype_digit($timestamp)) {
            return false;
        }

        $age = abs(time() - (int) $timestamp);
        if ($age > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $rawBody = $request->getContent();
        $sigBase = "v0:{$timestamp}:{$rawBody}";
        $expected = 'v0='.hash_hmac('sha256', $sigBase, $signingSecret);

        return hash_equals($expected, $slackSig);
    }
}
