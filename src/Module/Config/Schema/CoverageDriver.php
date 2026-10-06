<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

/**
 * Coverage driver used by differential tests.
 *
 * @internal
 */
enum CoverageDriver: string
{
    /**
     * Branch probes (no extension needed); see `docs/harness-protocol.md`.
     */
    case Auto = 'auto';
    case Probes = 'probes';
    case Xdebug = 'xdebug';
    case Pcov = 'pcov';
}
