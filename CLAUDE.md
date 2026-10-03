# gramphp-bundle — rules for agents

The Symfony integration of [gramphp](https://github.com/quazardous/gramphp).
Meant to be public (MIT): anything committed here is read by strangers.

## English everywhere

Code, comments, docblocks, README, CHANGELOG, commit messages and PR
descriptions are in English, whatever language the conversation is in.

## Nothing from a host application

The bundle stays generic. Never commit hostnames, secrets, dev-machine paths,
private tracker IDs or the vocabulary of an application that uses it. Context
about that application belongs in `CLAUDE.local.md` (not versioned).

## Thin

The bundle wires gramphp into Symfony and Doctrine; it decides nothing. Any
behaviour of a journal belongs in gramphp, with its contract tests there.

## The two schemas are one

`Schema\Tables` mirrors `MariadbDriver::schema()`. `SchemaTest` confronts them
on a live database and checks that a Doctrine diff on the driver's tables
proposes nothing: keep it green when gramphp's schema changes.

## Every supported version

CI runs the lowest dependencies (Symfony 6.4, DoctrineBundle 2, DBAL 4.0) and
the highest (Symfony 8, DoctrineBundle 3). A change must pass both.
