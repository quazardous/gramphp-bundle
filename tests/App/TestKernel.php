<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Quazardous\GramPHPBundle\GramPHPBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A minimal application: FrameworkBundle, DoctrineBundle (DBAL and ORM, no
 * entity) and the bundle, on the test MariaDB. Each kernel gets its own
 * gramphp configuration — and its own cache.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $gramphp */
    public function __construct(private readonly array $gramphp)
    {
        parent::__construct('test', false);     // no debug: no error handler left behind
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new GramPHPBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/gramphp-bundle/' . md5(serialize($this->gramphp)) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/gramphp-bundle/log';
    }

    /**
     * The DBAL connection parameters of the test database, from the same
     * variables as the core's tests.
     *
     * @return array<string, mixed>|null
     */
    public static function connection(): ?array
    {
        $dsn = getenv('GRAMPHP_TEST_MARIADB_DSN');
        if (false === $dsn || '' === $dsn) {
            return null;
        }
        $params = ['driver' => 'pdo_mysql', 'user' => (string) getenv('GRAMPHP_TEST_MARIADB_USER'), 'password' => (string) getenv('GRAMPHP_TEST_MARIADB_PASSWORD')];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            match ($name) {
                'host', 'dbname', 'charset' => $params[$name] = $value,
                'port' => $params['port'] = (int) $value,
                default => null,
            };
        }
        // gramphp's driver needs READ COMMITTED: set when the connection opens.
        $params['options'] = [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'];

        return $params;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false]);
        $container->extension('doctrine', [
            'dbal' => self::connection() ?? ['driver' => 'pdo_mysql', 'url' => 'mysql://none@localhost/none'],
            'orm' => ['mappings' => []],
        ]);
        $container->extension('gramphp', $this->gramphp);
    }
}
