<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('app.swagger_ui', '/api/doc')
        ->controller('nelmio_api_doc.controller.swagger_ui')
        ->defaults(['area' => 'default'])
        ->methods(['GET']);

    $routes->add('app.swagger_json', '/api/doc.json')
        ->controller('nelmio_api_doc.controller.swagger')
        ->defaults(['area' => 'default'])
        ->methods(['GET']);
};
