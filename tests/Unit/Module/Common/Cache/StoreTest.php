<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common\Cache;

use Internal\Path;
use Opmin\Module\Common\Cache\FileStore;
use Opmin\Module\Common\Cache\MemoryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FileStore::class)]
#[Covers(MemoryStore::class)]
final class StoreTest
{
    private string $dir;

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
    }
}
