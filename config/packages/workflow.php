<?php

declare(strict_types=1);

use App\Domain\Entity\LeaveRequest;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'workflows' => [
            'leave_request_approval' => [
                'type' => 'state_machine',
                'audit_trail' => [
                    'enabled' => '%kernel.debug%',
                ],
                'marking_store' => [
                    'type' => 'method',
                    'property' => 'status',
                ],
                'supports' => [LeaveRequest::class],
                'initial_marking' => 'pending',
                'places' => [
                    'pending',
                    'recorded',
                    'approved',
                    'rejected',
                    'cancelled',
                    'cancel_requested',
                ],
                'transitions' => [
                    'approve' => ['from' => 'pending', 'to' => 'approved'],
                    'reject' => ['from' => 'pending', 'to' => 'rejected'],
                    'cancel_pending' => ['from' => 'pending', 'to' => 'cancelled'],
                    'cancel_recorded' => ['from' => 'recorded', 'to' => 'cancelled'],
                    'request_cancel' => ['from' => 'approved', 'to' => 'cancel_requested'],
                    'confirm_cancel' => ['from' => 'cancel_requested', 'to' => 'cancelled'],
                    'deny_cancel' => ['from' => 'cancel_requested', 'to' => 'approved'],
                ],
            ],
        ],
    ]);
};
