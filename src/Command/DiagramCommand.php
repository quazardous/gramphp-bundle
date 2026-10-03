<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Command;

use Quazardous\GramPHP\Diagram;
use Quazardous\GramPHPBundle\JournalRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Draw a graph — Mermaid, a statechart, or Graphviz — with live counts if asked. */
#[AsCommand('gramphp:diagram', 'Draw a graph as Mermaid, a Mermaid state diagram, or Graphviz')]
final class DiagramCommand extends Command
{
    public function __construct(private readonly JournalRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('graph', InputArgument::REQUIRED, 'The graph to draw')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'mermaid, state or dot', 'mermaid')
            ->addOption('counts', null, InputOption::VALUE_NONE, 'Overlay the journal\'s counts per node (mermaid and dot)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $journal = $this->registry->journal(Input::text($input, 'graph'));
        $graph = $journal->graph ?? $journal->dag;
        $counts = true === $input->getOption('counts') ? $this->registry->transaction->run(static fn(): array => Diagram::overlay($journal)) : null;
        $drawn = match (Input::text($input, 'format')) {
            'mermaid' => Diagram::mermaid($graph, $counts),
            'state' => Diagram::stateDiagram($graph),
            'dot' => Diagram::dot($graph, $counts),
            default => null,
        };
        if (null === $drawn) {
            $output->writeln('<error>--format: mermaid, state or dot</error>');

            return Command::INVALID;
        }
        $output->write($drawn, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
