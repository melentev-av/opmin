<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

/**
 * Which files of a git working tree must be clean before a run that commits its steps.
 *
 * @internal
 */
enum RequireClean: string
{
    case Targets = 'targets';
    case All = 'all';
    case Off = 'off';
}
