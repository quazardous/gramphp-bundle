<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Command;

use Doctrine\DBAL\Connection;
use Quazardous\GramPHP\Driver\Mariadb\MariadbDriver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The gramphp tables, for an application without the ORM: print their DDL
 * (to paste into a migration), or create them. With the ORM, the tables join
 * the generated schema on their own, and a migration diff creates them.
 */
#[AsCommand('gramphp:schema', 'Print, or create, the gramphp tables')]
final class SchemaCommand extends Command
{
    /**
     * @param 'int'|'string'        $subjectType
     * @param array<string, string> $names
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $subjectType,
        private readonly array $names,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the CREATE TABLE statements')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Create the tables that do not exist yet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statements = MariadbDriver::schema($this->subjectType, ...$this->names);
        if (true === $input->getOption('force')) {
            foreach ($statements as $statement) {
                $this->connection->executeStatement($statement);
            }
            $output->writeln(\sprintf('%d table(s) ensured', \count($statements)));

            return Command::SUCCESS;
        }
        if (true === $input->getOption('dump-sql')) {
            foreach ($statements as $statement) {
                $output->writeln($statement . ';');
            }

            return Command::SUCCESS;
        }
        $output->writeln('<comment>Pass --dump-sql to print the statements, or --force to run them.</comment>');

        return Command::INVALID;
    }
}
