<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Schema;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * THE GRAMPHP TABLES AS DOCTRINE SCHEMA OBJECTS — the same tables
 * `MariadbDriver::schema()` writes as DDL, so that Doctrine's schema tool and
 * migrations see them as the application's own: created by a diff, never
 * dropped by one. A test confronts the two definitions on a live database,
 * and checks that a diff between them proposes nothing.
 *
 * The indexes carry the names MySQL gives the driver's unnamed `KEY`s — the
 * name of their first column — so that a diff does not rename them.
 */
final class Tables
{
    /**
     * @param 'int'|'string'        $subjectType
     * @param array<string, string> $names       table|revisions|history|limits|arrivals|subject => name
     */
    public function __construct(
        private readonly string $subjectType,
        private readonly array $names,
    ) {}

    /** Add the tables to `schema`, leaving a table the schema already has alone. */
    public function addTo(Schema $schema): void
    {
        $s = $this->names['subject'];
        $nodes = $this->table($schema, $this->names['table']);
        if (null !== $nodes) {
            $this->subject($nodes);
            $nodes->addColumn('node', Types::STRING, ['length' => 191]);
            $nodes->addColumn('status', Types::STRING, ['length' => 32]);
            $nodes->addColumn('started_at', Types::STRING, ['length' => 32]);
            $nodes->addColumn('finished_at', Types::STRING, ['length' => 32, 'notnull' => false]);
            $nodes->addColumn('lease', Types::STRING, ['length' => 191, 'notnull' => false]);
            self::primaryKey($nodes, [$s, 'node']);
            $nodes->addIndex(['node', 'status', 'started_at'], 'node');
        }
        $revisions = $this->table($schema, $this->names['revisions']);
        if (null !== $revisions) {
            $this->subject($revisions);
            $revisions->addColumn('revision', Types::INTEGER);
            $revisions->addColumn('policy', Types::STRING, ['length' => 191, 'notnull' => false]);
            $revisions->addColumn('version', Types::STRING, ['length' => 191, 'notnull' => false]);
            self::primaryKey($revisions, [$s]);
        }
        $history = $this->table($schema, $this->names['history']);
        if (null !== $history) {
            $history->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $this->subject($history);
            $history->addColumn('node', Types::STRING, ['length' => 191]);
            $history->addColumn('status', Types::STRING, ['length' => 32]);
            $history->addColumn('started_at', Types::STRING, ['length' => 32]);
            $history->addColumn('finished_at', Types::STRING, ['length' => 32, 'notnull' => false]);
            $history->addColumn('lease', Types::STRING, ['length' => 191, 'notnull' => false]);
            $history->addColumn('archived_at', Types::STRING, ['length' => 32]);
            $history->addColumn('reason', Types::STRING, ['length' => 32]);
            self::primaryKey($history, ['id']);
            $history->addIndex([$s, 'node', 'reason', 'archived_at'], $s);
            $history->addIndex(['archived_at'], 'archived_at');
        }
        $limits = $this->table($schema, $this->names['limits']);
        if (null !== $limits) {
            $limits->addColumn('`key`', Types::STRING, ['length' => 191]);
            $limits->addColumn('value', Types::FLOAT, ['notnull' => false]);
            self::primaryKey($limits, ['`key`']);
        }
        $arrivals = $this->table($schema, $this->names['arrivals']);
        if (null !== $arrivals) {
            $this->subject($arrivals);
            $arrivals->addColumn('node', Types::STRING, ['length' => 191]);
            $arrivals->addColumn('ref', Types::STRING, ['length' => 191, 'notnull' => false]);
            $arrivals->addColumn('place', Types::STRING, ['length' => 32]);
            $arrivals->addColumn('arrived_at', Types::STRING, ['length' => 32]);
            // TINYINT in the driver's DDL, which Doctrine reads as a boolean.
            $arrivals->addColumn('urgent', Types::BOOLEAN, ['default' => false]);
            $arrivals->addColumn('refs', Types::TEXT, ['length' => 16_777_215, 'notnull' => false]);
            self::primaryKey($arrivals, [$s, 'node']);
            $arrivals->addIndex(['node', 'arrived_at'], 'node');
        }
    }

    private function table(Schema $schema, string $name): ?Table
    {
        return $schema->hasTable($name) ? null : $schema->createTable($name);
    }

    private function subject(Table $table): void
    {
        'int' === $this->subjectType
            ? $table->addColumn($this->names['subject'], Types::BIGINT)
            : $table->addColumn($this->names['subject'], Types::STRING, ['length' => 255]);
    }

    /**
     * `setPrimaryKey` is the one way every DBAL 4.x knows; 4.5 deprecates it
     * in favour of an editor that 4.0 lacks.
     *
     * @param non-empty-list<string> $columns
     */
    private static function primaryKey(Table $table, array $columns): void
    {
        $table->setPrimaryKey($columns);
    }
}
