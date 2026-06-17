<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('symfonycasts_tailwind', [
        'binary_version' => 'v4.1.11',
    ]);
};
