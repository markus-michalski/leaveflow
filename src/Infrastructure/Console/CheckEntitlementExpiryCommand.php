<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Notification\EntitlementExpiryCheckHandler;
use App\Application\Notification\EntitlementExpiryCheckMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually trigger the EntitlementExpiringSoon sweep (normally run daily at
 * 03:00 by Symfony Scheduler).
 *
 * Useful for manual testing and ops one-shots — the scheduler worker
 * (`messenger:consume scheduler_notifications`) only fires on cron ticks,
 * which is unhelpful when verifying behavior right now. This command
 * invokes the handler directly with the same code path the scheduler uses,
 * so idempotency (expiry_warning_sent_at) and recipient filtering work
 * identically.
 *
 *     bin/console app:notifications:check-entitlement-expiry
 */
#[AsCommand(
    name: 'app:notifications:check-entitlement-expiry',
    description: 'Run the entitlement-expiry warning sweep now (bypasses the cron tick).',
)]
final class CheckEntitlementExpiryCommand extends Command
{
    public function __construct(private readonly EntitlementExpiryCheckHandler $handler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Entitlement-Expiry Notification Sweep');

        ($this->handler)(new EntitlementExpiryCheckMessage());

        $io->success('Sweep complete. Re-run is idempotent (each entitlement gets at most one warning).');

        return Command::SUCCESS;
    }
}
