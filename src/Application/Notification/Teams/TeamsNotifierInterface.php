<?php

declare(strict_types=1);

namespace App\Application\Notification\Teams;

interface TeamsNotifierInterface
{
    /**
     * @param array<string, mixed> $card
     */
    public function send(string $webhookUrl, array $card): void;
}
