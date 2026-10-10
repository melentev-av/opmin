<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common\Cache;

use Internal\Path;
use Opmin\Module\Common\Cache\FileStore;
use Opmin\Module\Common\Cache\MemoryStore;
use Opmin\Module\Common\Cache\SqliteStore;
use Opmin\Module\Common\Cache\StoreFactory;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\Schema\CacheDriver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(StoreFactory::class)]
final class StoreFactoryTest
{
    /**
     * @param class-string $store
     */
    #[DataSet([CacheDriver::Auto, true, SqliteStore::class], 'auto with pdo_sqlite')]
    #[DataSet([CacheDriver::Auto, false, FileStore::class], 'auto without pdo_sqlite')]
    #[DataSet([CacheDriver::Sqlite, true, SqliteStore::class], 'sqlite')]
    #[DataSet([CacheDriver::Files, true, FileStore::class], 'files with pdo_sqlite')]
    #[DataSet([CacheDriver::Files, false, FileStore::class], 'files without pdo_sqlite')]
    #[DataSet([CacheDriver::Memory, true, MemoryStore::class], 'memory')]
    public function createsTheStoreOfTheDriver(CacheDriver $driver, bool $sqlite, string $store): void
    {
        $created = (new StoreFactory($sqlite))->create($driver, Path::create('/nonexistent/opmin-cache'));

        Assert::same($created::class, $store);
    }

    public function autoNeverStaysAuto(): void
    {
        Assert::same((new StoreFactory(true))->driver(CacheDriver::Auto), CacheDriver::Sqlite);
        Assert::same((new StoreFactory(false))->driver(CacheDriver::Auto), CacheDriver::Files);
    }

    public function explicitSqliteWithoutPdoSqliteIsAConfigError(): never
    {
        Expect::exception(ConfigException::class)
            ->withMessageContaining('Config key `cache.driver` is `sqlite`, but the PHP running opmin');

        (new StoreFactory(false))->create(CacheDriver::Sqlite, Path::create('/nonexistent/opmin-cache'));
    }
}
