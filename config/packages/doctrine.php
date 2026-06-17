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
    $container->extension('doctrine', [
        'dbal' => [
            'url' => '%env(resolve:DATABASE_URL)%',
            // Server version is set in the DATABASE_URL env var (see .env).
            'profiling_collect_backtrace' => '%kernel.debug%',
            'charset' => 'utf8mb4',
            'default_table_options' => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ],
        'orm' => [
            'validate_xml_mapping' => true,
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'auto_mapping' => true,
            'mappings' => [
                'App' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/Domain/Entity',
                    'prefix' => 'App\Domain\Entity',
                    'alias' => 'App',
                ],
                'AppValueObject' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/Domain/ValueObject',
                    'prefix' => 'App\Domain\ValueObject',
                    'alias' => 'AppValueObject',
                ],
            ],
            'controller_resolver' => [
                'auto_mapping' => false,
            ],
        ],
    ]);

    if ('test' === $container->env()) {
        $container->extension('doctrine', [
            'dbal' => [
                // "TEST_TOKEN" is typically set by ParaTest
                'dbname_suffix' => '_test%env(default::TEST_TOKEN)%',
            ],
        ]);
    }

    if ('prod' === $container->env()) {
        $container->extension('doctrine', [
            'orm' => [
                'query_cache_driver' => [
                    'type' => 'pool',
                    'pool' => 'doctrine.system_cache_pool',
                ],
                'result_cache_driver' => [
                    'type' => 'pool',
                    'pool' => 'doctrine.result_cache_pool',
                ],
            ],
        ]);

        $container->extension('framework', [
            'cache' => [
                'pools' => [
                    'doctrine.result_cache_pool' => ['adapter' => 'cache.app'],
                    'doctrine.system_cache_pool' => ['adapter' => 'cache.system'],
                ],
            ],
        ]);
    }
};
