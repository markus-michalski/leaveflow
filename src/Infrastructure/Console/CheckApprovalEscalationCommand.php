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

namespace App\Infrastructure\Console;

use App\Application\Notification\ApprovalEscalationCheckHandler;
use App\Application\Notification\ApprovalEscalationCheckMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually trigger the ApprovalEscalation sweep (normally run hourly by
 * Symfony Scheduler).
 *
 * Useful for manual testing and ops one-shots — the scheduler worker
 * (`messenger:consume scheduler_notifications`) only fires on cron ticks.
 * This command invokes the handler directly with the same code path, so
 * idempotency (escalation_notified_at) and recipient resolution work
 * identically.
 *
 *     bin/console app:notifications:check-approval-escalation
 */
#[AsCommand(
    name: 'app:notifications:check-approval-escalation',
    description: 'Run the approval-escalation sweep now (bypasses the cron tick).',
)]
final class CheckApprovalEscalationCommand extends Command
{
    public function __construct(private readonly ApprovalEscalationCheckHandler $handler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Approval-Escalation Notification Sweep');

        ($this->handler)(new ApprovalEscalationCheckMessage());

        $io->success('Sweep complete. Re-run is idempotent (each request gets at most one escalation).');

        return Command::SUCCESS;
    }
}
