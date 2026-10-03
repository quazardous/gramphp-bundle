<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Schema;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Adds the gramphp tables to the schema the ORM generates, so that
 * `doctrine:schema:update` and `doctrine:migrations:diff` create them — and
 * never propose to drop them. Registered only when doctrine/orm is installed.
 */
final class SchemaListener
{
    public function __construct(private readonly Tables $tables) {}

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $this->tables->addTo($args->getSchema());
    }
}
