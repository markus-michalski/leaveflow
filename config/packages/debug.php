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
