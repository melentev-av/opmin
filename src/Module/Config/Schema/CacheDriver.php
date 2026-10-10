<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

/**
 * Where cached opcode counts and references are stored.
 *
 * @internal
 */
enum CacheDriver: string
{
    /**
     * SQLite when the PHP running opmin has `pdo_sqlite`, files otherwise.
     */
    case Auto = 'auto';

    /**
     * One database `cache.sqlite` in `cache.dir`, kept between runs. A config error without `pdo_sqlite`.
     */
    case Sqlite = 'sqlite';

    /**
     * A JSON file per entry in `cache.dir`, kept between runs.
     */
    case Files = 'files';

    /**
     * For the run only: every run compiles and parses the project anew.
     */
    case Memory = 'memory';
}
