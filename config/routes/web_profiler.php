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

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    if ('dev' === $routes->env()) {
        $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')
            ->prefix('/_wdt');

        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')
            ->prefix('/_profiler');
    }
};
