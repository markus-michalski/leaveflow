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
    $container->extension('framework', [
        'router' => [
            // Configure how to generate URLs in non-HTTP contexts, such as CLI commands.
            'default_uri' => '%env(DEFAULT_URI)%',
        ],
    ]);

    if ('prod' === $container->env()) {
        $container->extension('framework', [
            'router' => ['strict_requirements' => null],
        ]);
    }
};
