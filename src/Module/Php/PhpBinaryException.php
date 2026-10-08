<?php

declare(strict_types=1);

namespace Opmin\Module\Php;

/**
 * `php.binary` cannot be used: not found, too old, or without a working OPcache.
 *
 * The message tells the user what to fix.
 *
 * @internal
 */
final class PhpBinaryException extends \RuntimeException {}
