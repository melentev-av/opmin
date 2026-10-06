<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

/**
 * Test runner of the analyzed project.
 *
 * @internal
 */
enum TestRunner: string
{
    case Auto = 'auto';
    case PhpUnit = 'phpunit';
    case Pest = 'pest';
    case Testo = 'testo';
    case Command = 'command';
}
