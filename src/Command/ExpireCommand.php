<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Command;

use Quazardous\GramPHPBundle\JournalRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** The janitor: give back the leases held longer than their node allows. */
#[AsCommand('gramphp:expire', 'Give back the leases held longer than their node allows')]
final class ExpireCommand extends Command
{
    public function __construct(private readonly JournalRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('graphs', InputArgument::IS_ARRAY, 'The graphs to expire; all when none is named');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->registry->chosen(Input::names($input, 'graphs')) as $name) {
            $released = $this->registry->transaction->run(fn(): array => $this->registry->journal($name)->expire());
            $output->writeln(\sprintf('%s: %s', $name, [] === $released ? 'nothing to release' : Input::counts($released)));
        }

        return Command::SUCCESS;
    }
}
