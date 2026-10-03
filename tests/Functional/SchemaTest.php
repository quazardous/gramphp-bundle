<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Tests\Functional;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Quazardous\GramPHP\Driver\Mariadb\MariadbDriver;
use Quazardous\GramPHPBundle\Schema\Tables;

/** The tables Doctrine sees are the tables the driver expects. */
final class SchemaTest extends BundleTestCase
{
    /**
     * Both definitions on a live database, side by side: the DDL the driver
     * writes, and the Doctrine schema the bundle registers. Every column (type,
     * nullability) and every index (its columns, in order) must match.
     *
     * @return iterable<string, array{'int'|'string'}>
     */
    public static function subjectTypes(): iterable
    {
        yield 'int subjects' => ['int'];
        yield 'string subjects' => ['string'];
    }

    /** @param 'int'|'string' $type */
    #[\PHPUnit\Framework\Attributes\DataProvider('subjectTypes')]
    public function testTheDoctrineTablesAreTheDriversTables(string $type): void
    {
        $this->boot([]);
        $connection = $this->connection();
        $raw = $this->tables;
        $doctrine = array_map(static fn(string $n): string => 'subject' === $n ? $n : "{$n}_d", $this->tables);
        foreach (MariadbDriver::schema($type, ...$raw) as $statement) {
            $connection->executeStatement($statement);
        }
        $schema = new Schema();
        (new Tables($type, $doctrine))->addTo($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $statement) {
            $connection->executeStatement($statement);
        }
        try {
            foreach (['table', 'revisions', 'history', 'limits', 'arrivals'] as $which) {
                self::assertSame($this->shape($raw[$which]), $this->shape($doctrine[$which]), "{$which}: the Doctrine table differs from the driver's");
            }
        } finally {
            foreach (array_diff_key($doctrine, ['subject' => true]) as $table) {
                $connection->executeStatement("DROP TABLE IF EXISTS {$table}");
            }
        }
    }

    /**
     * What a migration diff would say about tables the driver's DDL created:
     * nothing. Otherwise every `doctrine:migrations:diff` would propose
     * ALTERs nobody asked for.
     *
     * @param 'int'|'string' $type
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('subjectTypes')]
    public function testADiffOnTheDriversTablesFindsNothingToChange(string $type): void
    {
        $this->boot([]);
        $connection = $this->connection();
        foreach (MariadbDriver::schema($type, ...$this->tables) as $statement) {
            $connection->executeStatement($statement);
        }
        $manager = $connection->createSchemaManager();
        $wanted = new Schema();
        (new Tables($type, $this->tables))->addTo($wanted);
        $changes = [];
        foreach (['table', 'revisions', 'history', 'limits', 'arrivals'] as $which) {
            $diff = $manager->createComparator()->compareTables($manager->introspectTable($this->tables[$which]), $wanted->getTable($this->tables[$which]));
            if (!$diff->isEmpty()) {
                $changes[] = implode('; ', $connection->getDatabasePlatform()->getAlterTableSQL($diff));
            }
        }
        self::assertSame([], $changes, 'a migration diff would alter the driver\'s tables');
    }

    public function testTheTablesJoinTheSchemaTheOrmGenerates(): void
    {
        $container = $this->boot([]);
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $schema = (new SchemaTool($em))->getSchemaFromMetadata([]);
        foreach (['table', 'revisions', 'history', 'limits', 'arrivals'] as $which) {
            self::assertTrue($schema->hasTable($this->tables[$which]), "{$which} is in the generated schema");
        }
    }

    public function testTheSchemaCommandPrintsTheDriversDdl(): void
    {
        $this->boot([]);
        $output = $this->command('gramphp:schema', ['--dump-sql' => true])->getDisplay();
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $this->tables['arrivals'], $output);
        self::assertSame(5, substr_count($output, 'CREATE TABLE'));
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** @return array{columns: list<string>, indexes: list<string>} */
    private function shape(string $table): array
    {
        $connection = $this->connection();
        // An integer's display width — tinyint(4), tinyint(1) — is no difference.
        $columns = array_map(
            static fn(array $c): string => (string) preg_replace('/^(\S+ \w*int)\(\d+\)/', '$1', implode(' ', array_map(self::text(...), $c))),
            $connection->fetchAllNumeric(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table],
            ),
        );
        $indexes = array_map(
            static fn(array $i): string => ('PRIMARY' === $i[0] ? 'PRIMARY' : 'KEY') . ' (' . self::text($i[1] ?? null) . ')',
            $connection->fetchAllNumeric(
                'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME',
                [$table],
            ),
        );
        sort($indexes);

        return ['columns' => $columns, 'indexes' => $indexes];
    }
}
