<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

/**
 * How differences in warnings/notices/deprecations between versions are treated.
 *
 * @internal
 */
enum WarningsPolicy: string
{
    case Strict = 'strict';
    case AllowRemoval = 'allow_removal';
}
