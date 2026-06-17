<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

// See configuration reference at https://symfony.com/bundles/SchebTwoFactorBundle/8.x/configuration.html
return static function (ContainerConfigurator $container): void {
    $container->extension('scheb_two_factor', [
        // Standard form-login tokens — no separate API auth path yet.
        'security_tokens' => [
            UsernamePasswordToken::class,
            PostAuthenticationToken::class,
        ],

        'totp' => [
            'enabled' => true,
            // Issuer shown in authenticator apps (Google Authenticator etc.)
            // so users can identify which LeaveFlow tenant the code belongs to.
            'server_name' => 'LeaveFlow',
            'issuer' => 'LeaveFlow',
            'template' => 'security/2fa_form.html.twig',
            // RFC 6238 defaults — matches Google Authenticator / Authy / 1Password.
            'parameters' => [
                'digits' => 6,
                'period' => 30,
                'algorithm' => 'sha1',
            ],
        ],

        'backup_codes' => [
            'enabled' => true,
        ],
    ]);
};
