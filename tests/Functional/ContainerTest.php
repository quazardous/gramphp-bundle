<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Tests\Functional;

use Quazardous\GramPHP\Driver\Mariadb\Transaction;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHPBundle\JournalRegistry;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/** Graphs in configuration become journals, over the application's connection. */
final class ContainerTest extends BundleTestCase
{
    public function testEachGraphIsAJournalService(): void
    {
        $container = $this->boot([
            'onboarding' => ['file' => self::fixture('onboarding.json')],
            'inline' => ['definition' => ['document' => ['name' => 'inline'], 'nodes' => ['only' => []]]],
        ]);
        $onboarding = $container->get('gramphp.journal.onboarding');
        self::assertInstanceOf(NodeJournal::class, $onboarding);
        self::assertSame('test/onboarding@1', $onboarding->version, 'pinned to the document of the file');
        self::assertInstanceOf(NodeJournal::class, $container->get('gramphp.journal.inline'));
        self::assertInstanceOf(Transaction::class, $container->get('gramphp.transaction'));
        $registry = $container->get(JournalRegistry::class);
        self::assertInstanceOf(JournalRegistry::class, $registry);
        self::assertSame(['onboarding', 'inline'], $registry->names());
        self::assertSame($onboarding, $registry->journal('onboarding'));
        self::assertFalse($container->has(NodeJournal::class), 'two graphs: no journal by type alone');
    }

    public function testOneGraphIsAutowiredByTypeToo(): void
    {
        $container = $this->boot(['onboarding' => ['file' => self::fixture('onboarding.json')]]);
        self::assertSame($container->get('gramphp.journal.onboarding'), $container->get(NodeJournal::class));
    }

    public function testAGraphIsAFileOrADefinitionNotBoth(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('one of the two');
        $this->boot(['twice' => ['file' => self::fixture('onboarding.json'), 'definition' => ['document' => ['name' => 'x'], 'nodes' => ['a' => []]]]]);
    }

    public function testAJournalWorksOnTheApplicationsConnection(): void
    {
        $container = $this->boot(['onboarding' => ['file' => self::fixture('onboarding.json')]]);
        self::assertSame(0, $this->command('gramphp:schema', ['--force' => true])->getStatusCode());
        $journal = $container->get(NodeJournal::class);
        self::assertInstanceOf(NodeJournal::class, $journal);
        $transaction = $container->get(Transaction::class);
        self::assertInstanceOf(Transaction::class, $transaction);
        $lease = $transaction->run(static fn() => $journal->claim('send', 10, ['a', 'b']));
        self::assertSame(['a', 'b'], $lease->subjects);
        $transaction->run(static fn(): int => $journal->conclude('send', $lease, $lease->token));
        self::assertSame(['send' => 'done'], array_map(static fn($s): string => $s->value, $journal->progress('a')));
    }
}
