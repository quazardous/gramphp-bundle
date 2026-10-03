<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Command;

use Quazardous\GramPHPBundle\JournalRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Where each node stands — counts, the oldest running, the next retry due,
 * and, with candidates configured, how many are ready and since when. Plain
 * numbers to sample and alert on; `--format=json` for a collector.
 */
#[AsCommand('gramphp:snapshot', 'Where each node of a graph stands, for monitoring')]
final class SnapshotCommand extends Command
{
    public function __construct(private readonly JournalRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('graphs', InputArgument::IS_ARRAY, 'The graphs to read; all when none is named')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = Input::text($input, 'format');
        if (!\in_array($format, ['table', 'json'], true)) {
            $output->writeln("<error>--format: table or json, not {$format}</error>");

            return Command::INVALID;
        }
        $all = [];
        foreach ($this->registry->chosen(Input::names($input, 'graphs')) as $name) {
            $candidates = $this->registry->candidates($name);
            $all[$name] = $this->registry->transaction->run(fn(): array => $this->registry->journal($name)->snapshot($candidates));
        }
        if ('json' === $format) {
            $output->writeln(json_encode($all, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        foreach ($all as $name => $snapshot) {
            $columns = [];
            foreach ($snapshot as $entry) {
                $columns += array_flip(array_keys($entry));
            }
            $table = (new Table($output))->setHeaderTitle($name)->setHeaders(['node', ...array_keys($columns)]);
            foreach ($snapshot as $node => $entry) {
                $table->addRow([$node, ...array_map(static fn(string $c): string => isset($entry[$c]) ? (string) $entry[$c] : '', array_map('strval', array_keys($columns)))]);
            }
            $table->render();
        }

        return Command::SUCCESS;
    }
}
