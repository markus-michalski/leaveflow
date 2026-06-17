<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'notifier' => [
            'chatter_transports' => [],
            'texter_transports' => [],
            'channel_policy' => [
                'urgent' => ['email'],
                'high' => ['email'],
                'medium' => ['email'],
                'low' => ['email'],
            ],
            'admin_recipients' => [
                ['email' => 'admin@example.com'],
            ],
        ],
    ]);
};
