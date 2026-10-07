<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Rector;

use Opmin\Info;
use Opmin\Module\Common\FileSystem\FS;

/**
 * Rector analyses code with PHPStan, which ships as `phpstan.phar`. Inside opmin's PHAR (and the static
 * binary) that is a PHAR in a PHAR, and PHP opens no nested PHAR: PHPStan's own autoloader finds nothing.
 *
 * So `phpstan.phar` is extracted once per opmin build into the temp directory (like the harness), and a copy
 * of PHPStan's `bootstrap.php` next to it — with the autoloader class renamed, since the original is already
 * declared — replaces the original autoloader.
 *
 * @internal
 */
final class PhpStanPhar
{
    private const SOURCE = Info::ROOT_DIR . '/vendor/phpstan/phpstan';

    public static function register(): void
    {
        if (\Phar::running(false) === '' || !\is_file(self::SOURCE . '/phpstan.phar')) {
            return;
        }

        $target = self::extract();
        \spl_autoload_unregister(['PHPStan\PharAutoloader', 'loadClass']);
        /** @psalm-suppress UnresolvableInclude The copy made by extract(). */
        require_once $target . '/bootstrap.php';
    }

    private static function extract(): string
    {
        $bootstrap = (string) \file_get_contents(self::SOURCE . '/bootstrap.php');
        $key = \substr(\hash('sha256', Info::version() . "\0" . (string) \filesize(self::SOURCE . '/phpstan.phar') . "\0" . $bootstrap), 0, 12);
        $env = \getenv('OPMIN_HARNESS_DIR');
        $root = \is_string($env) && $env !== '' ? $env : \sys_get_temp_dir();
        $target = $root . \DIRECTORY_SEPARATOR . 'opmin-phpstan-' . $key;
        if (\is_file($target . '/bootstrap.php')) {
            return $target;
        }

        # Into a temporary directory first, then renamed: parallel runs never see half a PHPStan.
        $tmp = $target . '.' . \bin2hex(\random_bytes(4));
        FS::mkdir($tmp);
        \copy(self::SOURCE . '/phpstan.phar', $tmp . '/phpstan.phar');
        \file_put_contents($tmp . '/bootstrap.php', \str_replace(
            ['final class PharAutoloader', '[PharAutoloader::class, \'loadClass\']);'],
            ['final class OpminPharAutoloader', '[OpminPharAutoloader::class, \'loadClass\'], true, true);'],
            $bootstrap,
        ));
        @\rename($tmp, $target) or FS::removeDir(\Internal\Path::create($tmp));

        return $target;
    }
}
