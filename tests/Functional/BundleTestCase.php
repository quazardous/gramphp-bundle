<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Quazardous\GramPHPBundle\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** A booted test application on tables of the test's own, dropped afterwards. */
abstract class BundleTestCase extends TestCase
{
    protected ?TestKernel $kernel = null;

    /** @var array<string, string> */
    protected array $tables = [];

    /** The exception handler before the kernel booted: FrameworkBundle 6.4 sets one of its own. */
    private mixed $handler = null;

    protected function setUp(): void
    {
        if (null === TestKernel::connection()) {
            self::markTestSkipped('set GRAMPHP_TEST_MARIADB_DSN to run the bundle against MariaDB');
        }
        $prefix = \sprintf('b%d_%s', getmypid(), substr(md5(static::class . $this->name()), 0, 8));
        $this->tables = ['table' => "{$prefix}_nodes", 'revisions' => "{$prefix}_rev", 'history' => "{$prefix}_hist", 'limits' => "{$prefix}_lim", 'arrivals' => "{$prefix}_arr", 'subject' => 'subject'];
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $connection = $this->connection();
            foreach (array_diff_key($this->tables, ['subject' => true]) as $table) {
                $connection->executeStatement("DROP TABLE IF EXISTS {$table}");
            }
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $this->tables['table'] . '_subjects');
            $this->kernel->shutdown();
            $this->kernel = null;
            // Pop what the boot pushed, down to the handler found before it.
            for ($i = 0; $i < 10; ++$i) {
                $current = set_exception_handler(null);
                restore_exception_handler();
                if ($current === $this->handler) {
                    break;
                }
                restore_exception_handler();
            }
        }
    }

    /** @param array<string, mixed> $graphs */
    protected function boot(array $graphs, string $subjectType = 'string'): ContainerInterface
    {
        $this->handler = set_exception_handler(null);
        restore_exception_handler();
        $kernel = new TestKernel(['subject_type' => $subjectType, 'tables' => $this->tables, 'graphs' => $graphs]);
        $kernel->boot();                         // a configuration refused throws here, before tearDown has a kernel
        $this->kernel = $kernel;

        $container = $kernel->getContainer()->get('test.service_container');

        return $container instanceof ContainerInterface ? $container : throw new \LogicException('no test container');
    }

    protected function connection(): Connection
    {
        $connection = $this->kernel?->getContainer()->get('doctrine.dbal.default_connection');

        return $connection instanceof Connection ? $connection : throw new \LogicException('no connection');
    }

    /** @param array<string, mixed> $input */
    protected function command(string $name, array $input = []): CommandTester
    {
        $kernel = $this->kernel ?? throw new \LogicException('boot first');
        $tester = new CommandTester((new Application($kernel))->find($name));
        $tester->execute($input);

        return $tester;
    }

    protected static function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }
}
