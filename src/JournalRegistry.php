<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle;

use Psr\Container\ContainerInterface;
use Quazardous\GramPHP\Driver\Mariadb\Query;
use Quazardous\GramPHP\Driver\Mariadb\Transaction;
use Quazardous\GramPHP\NodeJournal;

/**
 * Every journal the configuration declares, by graph name, with what the
 * janitor needs: the graph's candidates, and the transaction to run in.
 */
final class JournalRegistry
{
    /**
     * @param list<string>                                                $names
     * @param array<string, array{sql: string, params: list<mixed>}|null> $candidates
     */
    public function __construct(
        private readonly ContainerInterface $journals,
        private readonly array $names,
        private readonly array $candidates,
        public readonly Transaction $transaction,
    ) {}

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }

    public function journal(string $name): NodeJournal
    {
        if (!\in_array($name, $this->names, true)) {
            throw new \InvalidArgumentException(\sprintf("gramphp: no graph '%s' — the configuration declares [%s]", $name, implode(', ', $this->names)));
        }
        $journal = $this->journals->get($name);

        return $journal instanceof NodeJournal ? $journal : throw new \LogicException("gramphp: '{$name}' is not a journal");
    }

    /** The candidates the configuration gives this graph — the subjects the janitor looks at — or null. */
    public function candidates(string $name): ?Query
    {
        $this->journal($name);
        $candidates = $this->candidates[$name] ?? null;

        return null === $candidates ? null : new Query($candidates['sql'], $candidates['params']);
    }

    /**
     * The graphs a command names, or all of them.
     *
     * @param list<string> $asked
     *
     * @return list<string>
     */
    public function chosen(array $asked): array
    {
        foreach ($asked as $name) {
            $this->journal($name);
        }

        return [] === $asked ? $this->names : $asked;
    }
}
