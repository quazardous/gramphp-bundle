# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Graphs declared in configuration — a file in grampy's format, or the same
  form inline — become `NodeJournal` services (`gramphp.journal.<name>`),
  autowired by argument name (`NodeJournal $ordersJournal`), or by type when
  there is one graph; all over the application's Doctrine DBAL connection.
- `gramphp.transaction`: gramphp's `Transaction`, retrying a unit on a deadlock.
- The gramphp tables join the schema the ORM generates, so that
  `doctrine:migrations:diff` creates them and never drops them; a diff on
  tables the driver created proposes nothing. `gramphp:schema` prints or
  creates them for an application without the ORM.
- Janitor and monitoring commands: `gramphp:expire`, `gramphp:settle` (in
  bounded passes, on the candidates the configuration gives a graph),
  `gramphp:prune-history`, `gramphp:snapshot` (table or JSON).
- `gramphp:diagram`: a graph as Mermaid, a Mermaid state diagram or
  Graphviz, with live counts.
- Symfony 6.4, 7 and 8; DoctrineBundle 2 and 3.
