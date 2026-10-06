<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

/**
 * Which functions may get native type declarations changed.
 *
 * @internal
 */
enum NativeTypesPolicy: string
{
    case None = 'none';
    case NonOverridable = 'non_overridable';
    case All = 'all';
}
