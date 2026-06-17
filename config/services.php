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

use App\Application\Approval\ApprovalWorkflow;
use App\Application\Approval\ApproverResolver;
use App\Application\Approval\ApproverResolverInterface;
use App\Application\Notification\NotificationDispatcher;
use App\Application\Notification\NotificationDispatcherInterface;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', '../src/');

    // Interface aliases — extracted purely for unit-test mockability of
    // listeners that depend on them. Single implementation each.
    $services->alias(NotificationDispatcherInterface::class, NotificationDispatcher::class);
    $services->alias(ApproverResolverInterface::class, ApproverResolver::class);

    // DoctrineMigrationsBundle exposes the dependency factory under a
    // service-id alias only — explicit FQCN alias so the setup
    // requirements checker is autowirable.
    $services->alias(DependencyFactory::class, 'doctrine.migrations.dependency_factory')
        ->public(false);

    // Expose workflow services to integration tests. Without a real consumer
    // (controllers land later in the workflow), the container compiler inlines the
    // service away and self::getContainer()->get(...) fails in tests.
    if ('test' === $container->env()) {
        $services->set(ApprovalWorkflow::class)
            ->autowire()
            ->public();
    }
};
