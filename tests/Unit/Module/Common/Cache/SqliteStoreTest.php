<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common\Cache;

use Internal\Path;
use Opmin\Module\Common\Cache\SqliteStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Needs `pdo_sqlite` in the PHP running the tests (CI and the dev image have it).
 */
#[Test]
#[Covers(SqliteStore::class)]
final class SqliteStoreTest
{
    private string $dir;

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-sqlite-' . \bin2hex(\random_bytes(4));
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('chmod -R u+w ' . \escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . \escapeshellarg($this->dir));
    }

    public function keepsEntriesBetweenInstancesInOneFile(): void
    {
        (new SqliteStore(Path::create($this->dir)))->set('count', 'ab12', '{"a":1}');
        $store = new SqliteStore(Path::create($this->dir));

        Assert::same($store->get('count', 'ab12'), '{"a":1}');
        Assert::null($store->get('refs', 'ab12'));
        Assert::null($store->error());
        Assert::true(\is_file($this->dir . '/cache.sqlite'));
        Assert::false(\file_exists($this->dir . '/count'));
    }

    public function keepsBinaryValuesIntact(): void
    {
        $value = "\x00\xff{\"a\":\"\\u0000\"}\n" . \str_repeat('x', 100_000);
        (new SqliteStore(Path::create($this->dir)))->set('count', 'ab12', $value);

        Assert::same((new SqliteStore(Path::create($this->dir)))->get('count', 'ab12'), $value);
    }

    public function usesWriteAheadLog(): void
    {
        (new SqliteStore(Path::create($this->dir)))->set('count', 'ab12', '1');

        $mode = (new \PDO('sqlite:' . $this->dir . '/cache.sqlite'))->query('PRAGMA journal_mode')?->fetchColumn();

        Assert::same($mode, 'wal');
    }

    public function corruptDatabaseIsAMiss(): void
    {
        \mkdir($this->dir);
        \file_put_contents($this->dir . '/cache.sqlite', \str_repeat('not a database ', 1000));
        $store = new SqliteStore(Path::create($this->dir));

        $store->set('count', 'ab12', '{"a":1}');

        Assert::null($store->get('count', 'ab12'));
        Assert::string((string) $store->error())->contains('cache.sqlite');
    }

    public function directoryThatCannotBeCreatedIsAMiss(): void
    {
        \mkdir($this->dir);
        \file_put_contents($this->dir . '/file', '');
        $store = new SqliteStore(Path::create($this->dir . '/file/cache'));

        $store->set('count', 'ab12', '{"a":1}');

        Assert::null($store->get('count', 'ab12'));
        Assert::notNull($store->error());
    }

    public function writeBlockedByAParallelRunIsDroppedAfterTheTimeout(): void
    {
        $first = new SqliteStore(Path::create($this->dir));
        $first->set('count', 'ab12', 'old');
        $other = new \PDO('sqlite:' . $this->dir . '/cache.sqlite');
        $other->exec('BEGIN IMMEDIATE');
        $other->exec("INSERT OR REPLACE INTO entries (kind, hash, value) VALUES ('count', 'cd34', 'theirs')");
        $store = new SqliteStore(Path::create($this->dir), busyTimeoutMs: 50);

        $store->set('count', 'ef56', 'mine');

        # WAL: the reader is not blocked by the writer and sees the last committed state.
        Assert::same($store->get('count', 'ab12'), 'old');
        Assert::null($store->get('count', 'ef56'));
        $other->exec('COMMIT');
        $store->set('count', 'ef56', 'mine');
        Assert::same($store->get('count', 'ef56'), 'mine');
        Assert::same($store->get('count', 'cd34'), 'theirs');
    }
}
