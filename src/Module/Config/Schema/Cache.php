<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Cache of opcode counts, AST and indexes.
 *
 * @internal
 */
#[InflectableConfig]
final class Cache
{
    /** @var non-empty-string */
    #[ConfigKey('cache.dir', 'Cache directory; a relative one is next to the config file (add it to .gitignore)')]
    public string $dir = '.opmin-cache';

    #[ConfigKey('cache.driver', 'Storage of opcode counts and references: auto (sqlite when the PHP running opmin has pdo_sqlite, files otherwise) | sqlite (one database cache.sqlite in cache.dir, kept between runs; a config error without pdo_sqlite) | files (a JSON file per entry in cache.dir, kept between runs) | memory (this run only)')]
    public CacheDriver $driver = CacheDriver::Auto;

    #[ConfigKey('cache.recreate_corrupt', 'Delete a corrupt SQLite cache and start an empty one; off: the run works without the cache, opmin doctor reports the file to delete')]
    public bool $recreateCorrupt = false;
}
