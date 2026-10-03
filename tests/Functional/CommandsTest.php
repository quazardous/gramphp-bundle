<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Tests\Functional;

use Quazardous\GramPHP\Driver\Mariadb\Transaction;
use Quazardous\GramPHP\NodeJournal;
use Quazardous\GramPHP\Status;

/** The janitor, the monitoring and the drawing, from the console. */
final class CommandsTest extends BundleTestCase
{
    private NodeJournal $journal;

    private Transaction $transaction;

    /** Three subjects through `send`; the survey has a grace of one second. */
    private function onboarded(?string $candidates = 'default'): void
    {
        $subjects = $this->tables['table'] . '_subjects';
        $container = $this->boot(['onboarding' => [
            'file' => self::fixture('onboarding.json'),
            'candidates' => 'default' === $candidates ? "SELECT id FROM {$subjects} ORDER BY id" : $candidates,
        ]]);
        $this->command('gramphp:schema', ['--force' => true]);
        $this->connection()->executeStatement("CREATE TABLE {$subjects} (id VARCHAR(255) PRIMARY KEY)");
        $this->connection()->executeStatement("INSERT INTO {$subjects} VALUES ('a'), ('b'), ('c')");
        $journal = $container->get('gramphp.journal.onboarding');
        $transaction = $container->get('gramphp.transaction');
        self::assertInstanceOf(NodeJournal::class, $journal);
        self::assertInstanceOf(Transaction::class, $transaction);
        [$this->journal, $this->transaction] = [$journal, $transaction];
        $lease = $transaction->run(static fn() => $journal->claim('send', 10, ['a', 'b', 'c']));
        $transaction->run(static fn(): int => $journal->conclude('send', $lease, $lease->token));
    }

    public function testSettleSkipsWhatHasWaitedPastItsGrace(): void
    {
        $this->onboarded();
        sleep(2);                                    // past the survey's grace of one second
        $tester = $this->command('gramphp:settle', ['--limit' => '2']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('onboarding: survey skipped 3', $tester->getDisplay(), 'in passes of two, three in all');
        self::assertSame(Status::Skipped, $this->journal->progress('c')['survey'] ?? null);
    }

    public function testSettleWithoutCandidatesSaysWhatToConfigure(): void
    {
        $this->onboarded(null);
        $tester = $this->command('gramphp:settle');
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('gramphp.graphs.onboarding.candidates', $tester->getDisplay());
    }

    public function testExpireGivesBackNothingWithinTheLease(): void
    {
        $this->onboarded();
        $tester = $this->command('gramphp:expire');
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('onboarding: nothing to release', $tester->getDisplay());
    }

    public function testSnapshotGivesPlainNumbers(): void
    {
        $this->onboarded();
        $tester = $this->command('gramphp:snapshot', ['--format' => 'json']);
        self::assertSame(0, $tester->getStatusCode());
        $snapshot = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        self::assertSame(3, self::dig($snapshot, 'onboarding', 'send', 'done'));
        self::assertSame(3, self::dig($snapshot, 'onboarding', 'survey', 'ready'), 'the configured candidates give the ready count');
        self::assertStringContainsString('survey', $this->command('gramphp:snapshot')->getDisplay(), 'and a table by default');
    }

    private static function dig(mixed $value, string ...$path): mixed
    {
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    public function testPruneHistoryKeepsWhatABoundCounts(): void
    {
        $this->onboarded();
        $this->transaction->run(fn(): int => $this->journal->forget('send', ['a']));
        $tester = $this->command('gramphp:prune-history', ['--before' => '+1 day']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 history row(s)', $tester->getDisplay());
        self::assertSame([], $this->journal->history('a'));
    }

    public function testDiagramDrawsTheGraphWithItsCounts(): void
    {
        $this->onboarded();
        $mermaid = $this->command('gramphp:diagram', ['graph' => 'onboarding', '--counts' => true])->getDisplay();
        self::assertStringStartsWith('flowchart LR', $mermaid);
        self::assertStringContainsString('✓3', $mermaid);
        self::assertStringStartsWith('digraph gramphp', $this->command('gramphp:diagram', ['graph' => 'onboarding', '--format' => 'dot'])->getDisplay());
        self::assertStringStartsWith('stateDiagram-v2', $this->command('gramphp:diagram', ['graph' => 'onboarding', '--format' => 'state'])->getDisplay());
    }

    public function testAnUnknownGraphIsNamed(): void
    {
        $this->onboarded();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("no graph 'ghost'");
        $this->command('gramphp:expire', ['graphs' => ['ghost']]);
    }
}
