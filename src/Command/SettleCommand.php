<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Command;

use Quazardous\GramPHPBundle\JournalRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The janitor: conclude waits, let due arrivals through their lanes, skip
 * optional nodes past their grace — on the candidates the configuration gives
 * each graph, in passes of at most `--limit` per node until one writes nothing.
 */
#[AsCommand('gramphp:settle', 'Conclude waits, let due arrivals in, skip optional nodes past their grace')]
final class SettleCommand extends Command
{
    public function __construct(private readonly JournalRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('graphs', InputArgument::IS_ARRAY, 'The graphs to settle; all when none is named')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many subjects per node and pass, each pass its own transaction', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = Input::positive($input, 'limit');
        $status = Command::SUCCESS;
        foreach ($this->registry->chosen(Input::names($input, 'graphs')) as $name) {
            $candidates = $this->registry->candidates($name);
            if (null === $candidates) {
                $output->writeln("<error>{$name}: no candidates — set gramphp.graphs.{$name}.candidates to the SQL of the subjects to settle</error>");
                $status = Command::FAILURE;

                continue;
            }
            $total = [];
            do {
                $pass = $this->registry->transaction->run(fn(): array => $this->registry->journal($name)->settle($candidates, $limit));
                $written = 0;
                foreach ($pass as $node => $counts) {
                    foreach ($counts as $outcome => $count) {
                        $total["{$node} {$outcome}"] = ($total["{$node} {$outcome}"] ?? 0) + $count;
                        $written = max($written, $count);
                    }
                }
            } while ($written >= $limit);
            $output->writeln(\sprintf('%s: %s', $name, [] === $total ? 'nothing to settle' : Input::counts($total)));
        }

        return $status;
    }
}
