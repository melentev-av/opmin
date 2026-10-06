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
    case Auto = 'auto';
    case Xdebug = 'xdebug';
    case Pcov = 'pcov';
}
