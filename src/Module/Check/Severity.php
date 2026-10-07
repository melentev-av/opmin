<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

/**
 * @internal
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
