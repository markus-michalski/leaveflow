<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Entitlement\YearTransitionService;
use App\Application\Entitlement\YearTransitionStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates carryover entitlements from the given source year to the next year.
 *
 * Intended to be run manually once per year-end by an admin:
 *     bin/console app:entitlement:year-transition --year=2026
 *
 * `--dry-run` prints the report without persisting. Existing Carryover rows
 * for the target year are left untouched — rerun the command after manually
 * deleting them if you need to retry.
 */
#[AsCommand(
    name: 'app:entitlement:year-transition',
    description: 'Create carryover leave entitlements from remaining Regular balances.',
)]
final class YearTransitionCommand extends Command
{
    public function __construct(private readonly YearTransitionService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'year',
                null,
                InputOption::VALUE_REQUIRED,
                'Source year whose Regular entitlements are rolled over (defaults to last year).',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Compute and print the report without persisting changes.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourceYear = $input->getOption('year');
        if (null === $sourceYear) {
            $sourceYear = (int) (new \DateTimeImmutable())->format('Y') - 1;
        } else {
            $sourceYear = (int) $sourceYear;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $io->title(\sprintf(
            'Year transition %d → %d%s',
            $sourceYear,
            $sourceYear + 1,
            $dryRun ? ' (dry run)' : '',
        ));

        try {
            $entries = $this->service->transition($sourceYear, $dryRun);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $entries) {
            $io->info('No Regular entitlements found for source year — nothing to do.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn ($entry) => [
                $entry->employee->getFullName(),
                $entry->employee->getEmployeeNumber(),
                number_format($entry->hoursCarriedOver, 2, '.', ''),
                $entry->status->value,
            ],
            $entries,
        );
        $io->table(['Employee', 'Nr.', 'Hours', 'Status'], $rows);

        $created = array_filter($entries, static fn ($e): bool => YearTransitionStatus::Created === $e->status);
        $skippedEmpty = array_filter($entries, static fn ($e): bool => YearTransitionStatus::SkippedEmptyBalance === $e->status);
        $skippedExists = array_filter($entries, static fn ($e): bool => YearTransitionStatus::SkippedAlreadyExists === $e->status);

        $io->success(\sprintf(
            '%s%d created, %d skipped (empty balance), %d skipped (carryover already exists).',
            $dryRun ? 'Dry run: ' : '',
            \count($created),
            \count($skippedEmpty),
            \count($skippedExists),
        ));

        return Command::SUCCESS;
    }
}
