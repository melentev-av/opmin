<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Locate;

/**
 * Kind of a function-like unit of code, written to the JSON report.
 *
 * @internal
 */
enum UnitKind: string
{
    /**
     * Code outside functions: `file.php::<main>`.
     */
    case Main = 'main';
    case Function = 'function';
    case Method = 'method';

    /**
     * Property hook (PHP 8.4+): `Class::$prop::get`.
     */
    case Hook = 'hook';

    /**
     * Closure or arrow function: `Parent::{closure:N}`.
     */
    case Closure = 'closure';
}
