# gramphp-bundle

[![CI](https://github.com/quazardous/gramphp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/quazardous/gramphp-bundle/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/quazardous/gramphp-bundle)](https://packagist.org/packages/quazardous/gramphp-bundle)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The Symfony integration of [gramphp](https://github.com/quazardous/gramphp) —
a small workflow graph for work queues that already live in your database.
Graphs declared in configuration become journal services over your Doctrine
connection; the tables join your migrations; the janitor runs as commands.

```bash
composer require quazardous/gramphp-bundle
```

Symfony 6.4, 7 or 8; DoctrineBundle 2 or 3; MariaDB (or MySQL 8).

## Configure

```yaml
# config/packages/gramphp.yaml
gramphp:
    connection: default        # the Doctrine DBAL connection
    subject_type: int          # your ids: int or string
    graphs:
        orders:
            file: '%kernel.project_dir%/config/graphs/orders.json'
            # the subjects the janitor looks at — first column the subject, ORDER BY the priority
            candidates: 'SELECT id FROM orders WHERE archived = 0 ORDER BY id'
```

A graph file is gramphp's canonical form — grampy's format, the same file in
Python and PHP — as `Graph::toJson()` writes it. `definition:` takes the same
form inline.

gramphp's MariaDB driver needs **READ COMMITTED**. Set it when the connection
opens:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        options:
            !php/const PDO::MYSQL_ATTR_INIT_COMMAND: 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'
```

## Use

Each graph is a `NodeJournal` service, `gramphp.journal.<name>`, autowired by
argument name — or by type when there is a single graph:

```php
use Quazardous\GramPHP\Driver\Mariadb\Query;
use Quazardous\GramPHP\Driver\Mariadb\Transaction;
use Quazardous\GramPHP\NodeJournal;

final class ShipWorker
{
    public function __construct(
        private readonly NodeJournal $ordersJournal,
        private readonly Transaction $transaction,     // retries a unit on a deadlock
    ) {}

    public function __invoke(): void
    {
        $lease = $this->transaction->run(fn() => $this->ordersJournal->claim(
            'ship', 50, new Query('SELECT id FROM orders WHERE paid = 1 ORDER BY id'),
        ));
        foreach ($lease as $orderId) {
            // … ship it …
        }
        $this->transaction->run(fn() => $this->ordersJournal->conclude('ship', $lease, $lease->token));
    }
}
```

The journals run on your own connection: node rows and your writes can go in
one transaction.

## The tables

With the ORM, the gramphp tables join the schema it generates:
`doctrine:migrations:diff` creates them, and never proposes to drop them. A
test checks that a diff on tables gramphp's own DDL created proposes nothing.

Without the ORM, `bin/console gramphp:schema --dump-sql` prints the
`CREATE TABLE` statements for your migration; `--force` runs them.

## The janitor, the monitoring, the drawing

| command | does |
|---|---|
| `gramphp:expire [graphs…]` | gives back the leases held longer than their node allows |
| `gramphp:settle [graphs…] --limit=500` | concludes waits, lets due arrivals through their lanes, skips optional nodes past their grace — on the graph's `candidates`, in passes of `--limit` |
| `gramphp:prune-history --before='-30 days'` | deletes old history, except what a retry or loop bound counts |
| `gramphp:snapshot [graphs…] --format=table\|json` | where each node stands: counts, oldest running, next retry due, ready and since when |
| `gramphp:diagram <graph> --format=mermaid\|state\|dot --counts` | draws the graph, with live counts |

Schedule the janitor with cron or Symfony Scheduler; feed `snapshot` to your
monitoring.

## Development

Everything runs in Docker:

```bash
make build install
make test      # functional tests against MariaDB
make check     # PHPStan (max), coding style, tests — what CI runs
```

## License

MIT.
