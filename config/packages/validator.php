<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'validation' => [
            // Keep all user-facing strings in a single translation file.
            'translation_domain' => 'messages',
        ],
    ]);

    if ('test' === $container->env()) {
        $container->extension('framework', [
            'validation' => ['not_compromised_password' => false],
        ]);
    }
};
