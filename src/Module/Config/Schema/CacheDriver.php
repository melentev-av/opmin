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
     * A JSON file per entry in `cache.dir`, kept between runs.
     */
    case Files = 'files';

    /**
     * For the run only: every run compiles and parses the project anew.
     */
    case Memory = 'memory';
}
