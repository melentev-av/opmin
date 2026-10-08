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
    #[ConfigKey('cache.dir', 'Cache directory; a relative one is next to the config file, or in the current directory without one (add it to .gitignore)')]
    public string $dir = '.opmin-cache';

    #[ConfigKey('cache.driver', 'Storage of opcode counts and references: files (a JSON file per entry in cache.dir, kept between runs) | memory (this run only)')]
    public CacheDriver $driver = CacheDriver::Files;
}
