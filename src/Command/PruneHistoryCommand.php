<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Command;

use Quazardous\GramPHPBundle\JournalRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keep the history from growing forever: delete what was archived before
 * `--before`, except the rows a bound counts (retries, loop passes).
 */
#[AsCommand('gramphp:prune-history', 'Delete the history archived before a date, except what a bound counts')]
final class PruneHistoryCommand extends Command
{
    public function __construct(private readonly JournalRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('before', null, InputOption::VALUE_REQUIRED, 'Any date PHP reads, relative or not', '-30 days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = new \DateTimeImmutable(Input::text($input, 'before'), new \DateTimeZone('UTC'));
        $names = $this->registry->names();
        if ([] === $names) {
            $output->writeln('no graph is configured');

            return Command::SUCCESS;
        }
        // The history is one table for every graph: one journal prunes it.
        $journal = $this->registry->journal($names[0]);
        $deleted = $this->registry->transaction->run(static fn(): int => $journal->pruneHistory($before));
        $output->writeln(\sprintf('%d history row(s) archived before %s deleted', $deleted, $before->format(\DATE_ATOM)));

        return Command::SUCCESS;
    }
}
