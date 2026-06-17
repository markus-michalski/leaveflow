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
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

return static function (ContainerConfigurator $container): void {
    $container->extension('security', [
        // Internal SMB tool — surface precise login errors (wrong password vs.
        // deactivated account) so users know whether to reset their password
        // or contact HR/admin. Worth the tiny enumeration risk in context.
        'expose_security_errors' => 'all',

        'password_hashers' => [
            PasswordAuthenticatedUserInterface::class => 'auto',
        ],

        'providers' => [
            'app_user_provider' => [
                'entity' => [
                    'class' => 'App\Domain\Entity\User',
                    'property' => 'email',
                ],
            ],
        ],

        'role_hierarchy' => [
            'ROLE_MANAGER' => 'ROLE_EMPLOYEE',
            'ROLE_ADMIN' => 'ROLE_MANAGER',
        ],

        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_profiler|_wdt|assets|build)/',
                'security' => false,
            ],
            'api' => [
                // /api/doc and /api/doc.json are public (Swagger UI)
                'pattern' => '^/api/(?!doc)',
                'stateless' => true,
                'custom_authenticators' => [
                    'App\Infrastructure\Security\ApiTokenAuthenticator',
                ],
            ],
            'main' => [
                'lazy' => true,
                'provider' => 'app_user_provider',
                'user_checker' => 'App\Infrastructure\Security\UserChecker',

                'custom_authenticators' => [
                    'App\Infrastructure\Security\LdapAuthenticator',
                    'App\Infrastructure\Security\GoogleAuthenticator',
                    'App\Infrastructure\Security\EntraAuthenticator',
                ],

                'login_throttling' => [
                    'max_attempts' => 5,
                    'interval' => '15 minutes',
                ],

                'form_login' => [
                    'login_path' => 'app_login',
                    'check_path' => 'app_login',
                    'enable_csrf' => true,
                    'default_target_path' => 'app_dashboard',
                ],

                'logout' => [
                    'path' => 'app_logout',
                    'target' => 'app_login',
                ],

                'remember_me' => [
                    'secret' => '%kernel.secret%',
                    'lifetime' => 604800, // 7 days
                    'path' => '/',
                ],

                // Hook for scheb/2fa-bundle: after username/password succeeds,
                // users with TOTP enabled are routed through /2fa before their
                // session is considered fully authenticated. Backup codes are
                // accepted on the same form via the BackupCodeInterface impl.
                'two_factor' => [
                    'auth_form_path' => '2fa_login',
                    'check_path' => '2fa_login_check',
                    'csrf_token_id' => 'two_factor',
                    'csrf_parameter' => '_csrf_token',
                    'enable_csrf' => true,
                ],
            ],
        ],

        'access_control' => [
            ['path' => '^/api/doc', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/', 'roles' => 'ROLE_API'],
            ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/connect/google', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/connect/entra', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/reset-password', 'roles' => 'PUBLIC_ACCESS'],
            // First-run setup wizard — only reachable before any company exists.
            ['path' => '^/setup', 'roles' => 'PUBLIC_ACCESS'],
            // iCal feeds authenticate via URL token, not session cookie.
            ['path' => '^/ical/', 'roles' => 'PUBLIC_ACCESS'],
            // Slack incoming webhooks are authenticated via HMAC-SHA256 signature.
            ['path' => '^/webhook/slack/', 'roles' => 'PUBLIC_ACCESS'],
            // During a 2FA challenge the user holds IS_AUTHENTICATED_2FA_IN_PROGRESS.
            ['path' => '^/2fa', 'roles' => 'IS_AUTHENTICATED_2FA_IN_PROGRESS'],
            ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
            ['path' => '^/', 'roles' => 'ROLE_USER'],
        ],
    ]);

    if ('test' === $container->env()) {
        $container->extension('security', [
            'password_hashers' => [
                // Password hashers are resource-intensive by design.
                // In tests, reduce their cost for faster runs.
                PasswordAuthenticatedUserInterface::class => [
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
        ]);
    }
};
