<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('nelmio_api_doc', [
        'documentation' => [
            'info' => [
                'title' => 'LeaveFlow API',
                'description' => 'REST API for machine-to-machine integrations (HR systems, provisioning tools).',
                'version' => '1.0',
            ],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'API Token',
                    ],
                ],
            ],
            'security' => [
                ['BearerAuth' => []],
            ],
        ],
        'areas' => [
            'default' => [
                'path_patterns' => ['^/api/'],
                'host_patterns' => [],
            ],
        ],
    ]);
};
