<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Enable stateless CSRF protection for forms and logins/logouts
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'csrf_protection' => [
            'check_header' => true,
        ],
    ]);
};
