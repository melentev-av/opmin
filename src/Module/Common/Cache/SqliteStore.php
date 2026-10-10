<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Cache;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;

/**
 * `cache.driver: sqlite` (and `auto` with `pdo_sqlite`): every entry in one database, `<dir>/cache.sqlite`.
 * Kept between runs and shared by parallel ones: WAL lets readers work while another run writes, and a
 * writer waits for the lock up to the busy timeout.
 *
 * A database that cannot be opened (no permission, a corrupt or foreign file, a lock held longer than the
 * timeout) is a miss, never an error: the run counts without the cache and its writes are dropped. A corrupt
 * one is deleted and created anew only with `cache.recreate_corrupt`: a parallel run may still use the file.
 *
 * @internal
 */
final class SqliteStore implements Store
{
    public const FILE = 'cache.sqlite';

    /** How long a write waits for a parallel run's lock before it is dropped. */
    private const BUSY_TIMEOUT_MS = 5000;

    /** SQLite result codes of a damaged database file: `SQLITE_CORRUPT`, `SQLITE_NOTADB`. */
    private const CORRUPT_CODES = [11, 26];

    private ?\PDO $db = null;

    /** Why the database cannot be used; the store gives misses from then on. */
    private ?string $error = null;

    private ?\PDOStatement $select = null;
    private ?\PDOStatement $insert = null;

    /**
     * @param Path $dir `cache.dir`
     * @param non-negative-int $busyTimeoutMs
     * @param bool $recreateCorrupt `cache.recreate_corrupt`: delete a corrupt database and start an empty one.
     */
    public function __construct(
        private readonly Path $dir,
        private readonly int $busyTimeoutMs = self::BUSY_TIMEOUT_MS,
        private readonly bool $recreateCorrupt = false,
    ) {}

    /**
     * Whether the PHP running opmin can open SQLite databases at all.
     */
    public static function available(): bool
    {
        return \extension_loaded('pdo_sqlite');
    }

    public function file(): Path
    {
        return $this->dir->join(self::FILE);
    }

    /**
     * Opens the database: null when it works, otherwise the reason it is skipped (for `opmin doctor`).
     */
    public function error(): ?string
    {
        $this->db();

        return $this->error;
    }

    public function get(string $kind, string $key): ?string
    {
        try {
            $db = $this->db();
            if ($db === null) {
                return null;
            }

            $this->select ??= self::prepare($db, 'SELECT value FROM entries WHERE kind = ? AND hash = ?');
            $this->select->execute([$kind, $key]);
            /** @var mixed $value */
            $value = $this->select->fetchColumn();
            $this->select->closeCursor();

            return \is_string($value) ? $value : null;
        } catch (\Throwable) {
            # pdo_sqlite does not reset a statement that failed: executed again, it fails with "API misuse".
            $this->select = null;

            return null;
        }
    }

    public function set(string $kind, string $key, string $value): void
    {
        try {
            $db = $this->db();
            if ($db === null) {
                return;
            }

            $this->insert ??= self::prepare($db, 'INSERT OR REPLACE INTO entries (kind, hash, value) VALUES (?, ?, ?)');
            $this->insert->execute([$kind, $key, $value]);
        } catch (\Throwable) {
            # Locked longer than the busy timeout or the disk is full: the entry is recomputed next time.
            $this->insert = null;
        }
    }

    private static function prepare(\PDO $db, string $sql): \PDOStatement
    {
        return $db->prepare($sql) ?: throw new \RuntimeException('Failed to prepare: ' . $sql);
    }

    /**
     * The file is damaged or not a database at all; a lock, a missing permission or a full disk is not.
     */
    private static function corrupt(\Throwable $e): bool
    {
        return $e instanceof \PDOException && \in_array($e->errorInfo[1] ?? null, self::CORRUPT_CODES, true);
    }

    private function db(): ?\PDO
    {
        if ($this->db !== null || $this->error !== null) {
            return $this->db;
        }

        try {
            return $this->db = $this->open();
        } catch (\Throwable $e) {
            if ($this->recreateCorrupt && self::corrupt($e)) {
                # The WAL and the shared memory belong to the damaged file: they go with it.
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @\unlink((string) $this->file() . $suffix);
                }

                try {
                    return $this->db = $this->open();
                } catch (\Throwable $e) {
                }
            }

            $this->error = \sprintf('%s: %s', (string) $this->file(), $e->getMessage());

            return null;
        }
    }

    private function open(): \PDO
    {
        FS::mkdir($this->dir);
        $db = new \PDO('sqlite:' . (string) $this->file(), options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout = ' . $this->busyTimeoutMs);
        # The first statements read the file: a corrupt or foreign one fails here, not on every entry.
        $db->query('PRAGMA journal_mode = WAL');
        # A cache may lose the last entries on a power cut, never its consistency: no fsync per write.
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec(
            'CREATE TABLE IF NOT EXISTS entries ('
            . 'kind TEXT NOT NULL, hash TEXT NOT NULL, value BLOB NOT NULL, PRIMARY KEY (kind, hash)'
            . ') WITHOUT ROWID',
        );

        return $db;
    }
}
