<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    if ('dev' === $container->env()) {
        $container->extension('debug', [
            // Forwards VarDumper Data clones to a centralized server.
            // See the "server:dump" command to start a new server.
            'dump_destination' => 'tcp://%env(VAR_DUMPER_SERVER)%',
        ]);
    }
};
