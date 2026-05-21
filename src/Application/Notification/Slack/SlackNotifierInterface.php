<?php

declare(strict_types=1);

namespace App\Application\Notification\Slack;

interface SlackNotifierInterface
{
    /**
     * Posts a Block Kit message to a Slack channel using chat.postMessage.
     * Returns the message timestamp (ts) or null on failure.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function postMessage(string $botToken, string $channelId, array $blocks, string $text = ''): ?string;

    /**
     * Sends a response to a Slack response_url (interactive callback acknowledgement).
     *
     * @param array<string, mixed> $payload
     */
    public function sendResponseUrl(string $responseUrl, array $payload): void;

    /**
     * Opens or looks up an IM (DM) channel with a Slack user.
     * Returns the channel ID or null on failure.
     */
    public function openDm(string $botToken, string $slackUserId): ?string;

    /**
     * Opens a Block Kit modal using views.open.
     *
     * @param array<string, mixed> $view
     */
    public function openModal(string $botToken, string $triggerId, array $view): void;
}
