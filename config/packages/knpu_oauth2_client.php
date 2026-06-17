<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('knpu_oauth2_client', [
        'clients' => [
            'google' => [
                'type' => 'google',
                'client_id' => '%env(GOOGLE_CLIENT_ID)%',
                'client_secret' => '%env(GOOGLE_CLIENT_SECRET)%',
                'redirect_route' => 'connect_google_check',
                'redirect_params' => [],
                // hosted_domain is validated in GoogleAuthenticator against the
                // company setting — not hardcoded here so it can change at runtime.
            ],
            'azure' => [
                'type' => 'azure',
                'client_id' => '%env(ENTRA_CLIENT_ID)%',
                'client_secret' => '%env(ENTRA_CLIENT_SECRET)%',
                'redirect_route' => 'connect_entra_check',
                'redirect_params' => [],
                'tenant' => '%env(ENTRA_TENANT_ID)%',
                // v2.0 endpoint; auth_with_resource=false omits the resource
                // parameter so OIDC scopes (openid/profile/email) work correctly.
                'default_end_point_version' => '2.0',
                'auth_with_resource' => false,
            ],
        ],
    ]);
};
