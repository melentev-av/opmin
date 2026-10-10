<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common\Cache;

use Internal\Path;
use Opmin\Module\Common\Cache\FileStore;
use Opmin\Module\Common\Cache\MemoryStore;
use Opmin\Module\Common\Cache\SqliteStore;
use Opmin\Module\Common\Cache\Store;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FileStore::class)]
#[Covers(MemoryStore::class)]
#[Covers(SqliteStore::class)]
final class StoreTest
{
    private string $dir;

    /**
     * @return iterable<string, array{\Closure(Path): Store}>
     */
    public static function stores(): iterable
    {
        yield 'files' => [static fn(Path $dir): Store => new FileStore($dir)];
        yield 'memory' => [static fn(Path $dir): Store => new MemoryStore()];
        yield 'sqlite' => [static fn(Path $dir): Store => new SqliteStore($dir)];
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-store-' . \bin2hex(\random_bytes(4));
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    /**
     * @param \Closure(Path): Store $create
     */
    #[DataProvider('stores')]
    public function roundTripsAndKeepsKindsApart(\Closure $create): void
    {
        $store = $create(Path::create($this->dir));
        $store->set('count', 'ab12', '{"a":1}');
        $store->set('refs', 'ab12', '{"r":2}');
        $store->set('count', 'ab12', '{"a":3}');

        Assert::same($store->get('count', 'ab12'), '{"a":3}');
        Assert::same($store->get('refs', 'ab12'), '{"r":2}');
        Assert::null($store->get('count', 'ab13'));
        Assert::null($store->get('other', 'ab12'));
    }

    public function fileStoreKeepsEntriesBetweenInstancesAndKinds(): void
    {
        (new FileStore(Path::create($this->dir)))->set('count', 'ab12', '{"a":1}');
        $store = new FileStore(Path::create($this->dir));

        Assert::same($store->get('count', 'ab12'), '{"a":1}');
        Assert::null($store->get('refs', 'ab12'));
        Assert::null($store->get('count', 'ab13'));
        Assert::true(\is_file($this->dir . '/count/ab/ab12.json'));
    }

    public function memoryStoreWritesNothing(): void
    {
        $store = new MemoryStore();
        $store->set('count', 'ab12', '{"a":1}');

        Assert::same($store->get('count', 'ab12'), '{"a":1}');
        Assert::null($store->get('refs', 'ab12'));
        Assert::null((new MemoryStore())->get('count', 'ab12'));
        Assert::false(\file_exists($this->dir));
    }
}
