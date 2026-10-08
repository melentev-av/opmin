<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration;

use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryProbe;

/**
 * The `php.binary` the integration tests count opcodes with.
 *
 * `OPMIN_TEST_PHP_BINARY` selects it (the CI matrix runs the tests with PHP 8.1–8.5 as php.binary
 * while opmin itself runs on 8.3+); by default it is the PHP running the tests.
 */
final class TestPhp
{
    /**
     * @return non-empty-string
     */
    public static function path(): string
    {
        $env = \getenv('OPMIN_TEST_PHP_BINARY');

        return \is_string($env) && $env !== '' ? $env : \PHP_BINARY;
    }

    public static function binary(): PhpBinary
    {
        static $binary = null;

        return $binary ??= (new PhpBinaryProbe())->probe(self::path());
    }

    /**
     * `8.1` … `8.5`.
     */
    public static function minor(): string
    {
        return \implode('.', \array_slice(\explode('.', self::binary()->version), 0, 2));
    }
}
