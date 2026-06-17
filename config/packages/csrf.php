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

// Enable stateless CSRF protection for forms and logins/logouts
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'form' => [
            'csrf_protection' => ['token_id' => 'submit'],
        ],
        'csrf_protection' => [
            'stateless_token_ids' => ['submit', 'authenticate', 'logout'],
        ],
    ]);
};
