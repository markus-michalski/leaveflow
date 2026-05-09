<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Scheduler\ScheduledJobConfigManagerInterface;
use App\Domain\Repository\ScheduledJobConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bridge for #35 phase 2 admins until the phase-3 admin UI lands —
 * lets ops toggle individual scheduled jobs on/off and inspect the
 * last-run state without poking SQL by hand.
 *
 * Usage:
 *   bin/console app:scheduled-job:toggle list
 *   bin/console app:scheduled-job:toggle on <name>
 *   bin/console app:scheduled-job:toggle off <name>
 *
 * Job names are stable identifiers exposed as JOB_NAME constants on
 * each handler (e.g. \App\Application\Entitlement\YearTransitionHandler::JOB_NAME).
 */
#[AsCommand(
    name: 'app:scheduled-job:toggle',
    description: 'List or toggle scheduled jobs (year-transition, entitlement-expiry-check, approval-escalation-check).',
)]
final class ScheduledJobToggleCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobConfigRepository $repository,
        private readonly ScheduledJobConfigManagerInterface $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'list | on | off',
            )
            ->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'Job name (required for on/off, ignored for list).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        return match ($action) {
            'list' => $this->list($io),
            'on' => $this->toggle($io, (string) $input->getArgument('name'), enabled: true),
            'off' => $this->toggle($io, (string) $input->getArgument('name'), enabled: false),
            default => $this->unknownAction($io, $action),
        };
    }

    private function list(SymfonyStyle $io): int
    {
        $configs = $this->repository->findAllOrdered();
        if ([] === $configs) {
            $io->info('No scheduled-job configs persisted yet — handlers auto-provision rows on first run.');

            return Command::SUCCESS;
        }

        $rows = array_map(static function ($c): array {
            $status = $c->getLastRunStatus();
            $lastRun = $c->getLastRunAt();

            return [
                $c->getName(),
                $c->isEnabled() ? 'on' : 'off',
                null !== $lastRun ? $lastRun->format('Y-m-d H:i') : '—',
                null !== $status ? $status->value : '—',
                $c->getLastError() ?? '',
            ];
        }, $configs);

        $io->table(['Name', 'Toggle', 'Last run', 'Status', 'Last error'], $rows);

        return Command::SUCCESS;
    }

    private function toggle(SymfonyStyle $io, string $name, bool $enabled): int
    {
        if ('' === trim($name)) {
            $io->error('Job name required. Try "list" to see available jobs.');

            return Command::FAILURE;
        }

        $this->manager->setEnabled($name, $enabled);
        $io->success(\sprintf('Job "%s" is now %s.', $name, $enabled ? 'enabled' : 'disabled'));

        return Command::SUCCESS;
    }

    private function unknownAction(SymfonyStyle $io, string $action): int
    {
        $io->error(\sprintf('Unknown action "%s". Use list, on, or off.', $action));

        return Command::FAILURE;
    }
}
