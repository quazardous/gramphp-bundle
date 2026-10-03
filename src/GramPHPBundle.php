<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle;

use Doctrine\ORM\Tools\ToolEvents;
use Quazardous\GramPHP\Driver\Mariadb\DbalSql;
use Quazardous\GramPHP\Driver\Mariadb\MariadbDriver;
use Quazardous\GramPHP\Driver\Mariadb\Transaction;
use Quazardous\GramPHP\Graph;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHPBundle\Command\DiagramCommand;
use Quazardous\GramPHPBundle\Command\ExpireCommand;
use Quazardous\GramPHPBundle\Command\PruneHistoryCommand;
use Quazardous\GramPHPBundle\Command\SchemaCommand;
use Quazardous\GramPHPBundle\Command\SettleCommand;
use Quazardous\GramPHPBundle\Command\SnapshotCommand;
use Quazardous\GramPHPBundle\Schema\SchemaListener;
use Quazardous\GramPHPBundle\Schema\Tables;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * GRAPHS DECLARED IN CONFIGURATION, JOURNALS AS SERVICES.
 *
 *     gramphp:
 *         connection: default            # the Doctrine DBAL connection
 *         subject_type: int              # your ids: int or string
 *         graphs:
 *             orders:
 *                 file: '%kernel.project_dir%/config/graphs/orders.json'   # grampy's format
 *                 candidates: 'SELECT id FROM orders WHERE archived = 0 ORDER BY id'
 *
 * Each graph is a `NodeJournal` service, `gramphp.journal.<name>`, autowired
 * by argument name: `NodeJournal $ordersJournal`. One graph alone is also
 * autowired by type. All journals share the driver, over the application's
 * own connection — node rows and the application's writes go in one
 * transaction — and `Transaction` (`gramphp.transaction`) runs a unit and
 * retries it on a deadlock.
 */
final class GramPHPBundle extends AbstractBundle
{
    protected string $extensionAlias = 'gramphp';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('connection')->defaultValue('default')->info('The Doctrine DBAL connection the tables live on')->end()
                ->enumNode('subject_type')->values(['int', 'string'])->defaultValue('string')->info('The type of your subject ids')->end()
                ->arrayNode('tables')->addDefaultsIfNotSet()->info('The names of the tables, and of their subject column')
                    ->children()
                        ->scalarNode('table')->defaultValue('grampy_nodes')->end()
                        ->scalarNode('revisions')->defaultValue('grampy_revisions')->end()
                        ->scalarNode('history')->defaultValue('grampy_history')->end()
                        ->scalarNode('limits')->defaultValue('grampy_limits')->end()
                        ->scalarNode('arrivals')->defaultValue('grampy_arrivals')->end()
                        ->scalarNode('subject')->defaultValue('subject')->end()
                    ->end()
                ->end()
                ->booleanNode('schema')->defaultTrue()->info('Add the tables to the schema the ORM generates (doctrine:migrations:diff sees them)')->end()
                ->integerNode('attempts')->defaultValue(5)->min(1)->info('How many times a unit runs, at most, when InnoDB deadlocks it')->end()
                ->arrayNode('graphs')->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('file')->defaultNull()->info('A graph file in grampy\'s format (Graph::toJson())')->end()
                            ->variableNode('definition')->defaultNull()->info('The same form, inline')->end()
                            ->scalarNode('candidates')->defaultNull()->info('SQL of the subjects the janitor looks at: the first column the subject, the ORDER BY the priority')->end()
                            ->arrayNode('candidates_params')->scalarPrototype()->end()->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn(array $g): bool => (null === $g['file']) === (null === $g['definition']))
                            ->thenInvalid('a graph is declared by a `file` or by a `definition`, one of the two')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{connection: string, subject_type: 'int'|'string', tables: array<string, string>, schema: bool, attempts: int, graphs: array<string, array{file: ?string, definition: mixed, candidates: ?string, candidates_params: list<mixed>}>} $config */
        $connection = new Reference(\sprintf('doctrine.dbal.%s_connection', $config['connection']));
        $tables = $config['tables'];

        $builder->setDefinition('gramphp.sql', new Definition(DbalSql::class, [$connection]));
        $builder->setDefinition('gramphp.driver', new Definition(MariadbDriver::class, [
            new Reference('gramphp.sql'), $config['subject_type'],
            $tables['table'], $tables['revisions'], $tables['history'], $tables['limits'], $tables['subject'], $tables['arrivals'],
        ]));
        $builder->setDefinition('gramphp.transaction', new Definition(Transaction::class, [new Reference('gramphp.sql'), $config['attempts']]));
        $builder->setAlias(Transaction::class, 'gramphp.transaction');

        $journals = [];
        $candidates = [];
        foreach ($config['graphs'] as $name => $graph) {
            $graphDefinition = null !== $graph['file']
                ? (new Definition(Graph::class, [$graph['file']]))->setFactory([GraphLoader::class, 'fromFile'])
                : (new Definition(Graph::class, [$graph['definition']]))->setFactory([GraphLoader::class, 'fromDefinition']);
            $builder->setDefinition("gramphp.graph.{$name}", $graphDefinition);
            $builder->setDefinition("gramphp.journal.{$name}", (new Definition(NodeJournal::class, [new Reference('gramphp.driver'), new Reference("gramphp.graph.{$name}")]))->setPublic(true));
            $builder->registerAliasForArgument("gramphp.journal.{$name}", NodeJournal::class, "{$name}Journal");
            $journals[$name] = new Reference("gramphp.journal.{$name}");
            $candidates[$name] = null === $graph['candidates'] ? null : ['sql' => $graph['candidates'], 'params' => $graph['candidates_params']];
        }
        if (1 === \count($journals)) {
            $builder->setAlias(NodeJournal::class, 'gramphp.journal.' . array_key_first($journals));
        }
        $builder->setDefinition(JournalRegistry::class, new Definition(JournalRegistry::class, [
            new ServiceLocatorArgument($journals), array_map('strval', array_keys($journals)), $candidates, new Reference('gramphp.transaction'),
        ]));

        foreach ([ExpireCommand::class, SettleCommand::class, PruneHistoryCommand::class, SnapshotCommand::class, DiagramCommand::class] as $command) {
            $builder->setDefinition($command, (new Definition($command, [new Reference(JournalRegistry::class)]))->addTag('console.command'));
        }
        $builder->setDefinition(SchemaCommand::class, (new Definition(SchemaCommand::class, [$connection, $config['subject_type'], $tables]))->addTag('console.command'));

        $builder->setDefinition('gramphp.schema.tables', new Definition(Tables::class, [$config['subject_type'], $tables]));
        if ($config['schema'] && class_exists(ToolEvents::class)) {
            $builder->setDefinition('gramphp.schema.listener', (new Definition(SchemaListener::class, [new Reference('gramphp.schema.tables')]))
                ->addTag('doctrine.event_listener', ['event' => ToolEvents::postGenerateSchema, 'connection' => $config['connection']]));
        }
    }
}
