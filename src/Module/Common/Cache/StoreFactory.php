<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Cache;

use Internal\Path;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\Schema\CacheDriver;

/**
 * The store of `cache.driver`. `auto` is SQLite when the PHP running opmin has `pdo_sqlite` and files
 * otherwise; an explicit `sqlite` without it is a config error rather than a silent switch to another store.
 *
 * @internal
 */
final readonly class StoreFactory
{
    public function __construct(
        private bool $sqliteAvailable,
    ) {}

    public static function forThisPhp(): self
    {
        return new self(SqliteStore::available());
    }

    /**
     * The driver in effect, never `auto`.
     *
     * @throws ConfigException `sqlite` without `pdo_sqlite`.
     */
    public function driver(CacheDriver $configured): CacheDriver
    {
        return match ($configured) {
            CacheDriver::Auto => $this->sqliteAvailable ? CacheDriver::Sqlite : CacheDriver::Files,
            CacheDriver::Sqlite => $this->sqliteAvailable ? CacheDriver::Sqlite : throw new ConfigException(\sprintf(
                'Config key `cache.driver` is `sqlite`, but the PHP running opmin (%s) has no pdo_sqlite extension: '
                . 'install it, or set cache.driver to `auto` (SQLite when available, files otherwise) or `files`.',
                \PHP_VERSION,
            )),
            default => $configured,
        };
    }

    /**
     * @param Path $dir `cache.dir`, absolute.
     *
     * @throws ConfigException `sqlite` without `pdo_sqlite`.
     */
    public function create(CacheDriver $configured, Path $dir): Store
    {
        return match ($this->driver($configured)) {
            CacheDriver::Sqlite => new SqliteStore($dir),
            CacheDriver::Memory => new MemoryStore(),
            default => new FileStore($dir),
        };
    }
}
