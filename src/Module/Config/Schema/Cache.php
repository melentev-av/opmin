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
    #[ConfigKey('cache.dir', 'Cache directory (add it to .gitignore)')]
    public string $dir = '.opmin-cache';
}
