<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'messenger' => [
            'failure_transport' => 'failed',
            'transports' => [
                'async' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    'retry_strategy' => [
                        'max_retries' => 3,
                        'multiplier' => 2,
                    ],
                ],
                'sync' => 'sync://',
                'failed' => 'doctrine://default?queue_name=failed',
            ],
            'default_bus' => 'messenger.bus.default',
            'buses' => [
                'messenger.bus.default' => [],
            ],
            'routing' => [
                SendEmailMessage::class => 'async',
                ChatMessage::class => 'async',
                SmsMessage::class => 'async',
            ],
        ],
    ]);

    if ('dev' === $container->env()) {
        $container->extension('framework', [
            'messenger' => [
                // Dev UX: mails go out immediately so developers see Mailpit
                // without having to run a messenger worker. Production keeps
                // them async for back-pressure resilience.
                'routing' => [SendEmailMessage::class => 'sync'],
            ],
        ]);
    }

    if ('test' === $container->env()) {
        $container->extension('framework', [
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [SendEmailMessage::class => 'sync'],
            ],
        ]);
    }
};
