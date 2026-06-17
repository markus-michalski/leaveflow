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
        'default_locale' => 'de',
        'enabled_locales' => ['de', 'en'],
        'set_locale_from_accept_language' => true,
        'translator' => [
            'default_path' => '%kernel.project_dir%/translations',
            'fallbacks' => ['de', 'en'],
            'providers' => [],
        ],
    ]);

    if ('test' === $container->env()) {
        $container->extension('framework', [
            'set_locale_from_accept_language' => false,
        ]);
    }
};
