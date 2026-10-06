<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode;

use Opmin\Module\Php\PhpBinary;

/**
 * The OPcache settings opcodes are counted with, and their hash.
 *
 * Counts are comparable only when they were taken by the same PHP version with the same settings,
 * so reports carry `php` and `optimizer_hash`, and `diff`/`check` refuse to compare otherwise.
 *
 * @internal
 */
final class OptimizerSettings
{
    /**
     * INI overrides of every compile run. They also neutralize the user's php.ini: no file cache,
     * no JIT, no preloading (it would compile and dump other files), no Xdebug (its develop mode
     * adds EXT_STMT opcodes).
     *
     * @var array<non-empty-string, string>
     */
    public const INI = [
        'opcache.enable' => '1',
        'opcache.enable_cli' => '1',
        'opcache.file_cache' => '',
        'opcache.file_cache_only' => '0',
        'opcache.jit' => 'disable',
        'opcache.preload' => '',
        'opcache.restrict_api' => '',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '100000',
        # Files changed less than 2 s ago are compiled but neither optimized nor dumped by default —
        # exactly the files opmin has just rewritten.
        'opcache.file_update_protection' => '0',
        # All passes except those that are off by default in php.ini-production.
        'opcache.optimization_level' => '0x7FFEBFFF',
        # Dump both phases: before the optimizer (ops_raw) and after it (ops_opt).
        'opcache.opt_debug_level' => '0x30000',
        'xdebug.mode' => 'off',
        'display_errors' => '1',
        'error_reporting' => '-1',
        'log_errors' => '0',
        'html_errors' => '0',
        'auto_prepend_file' => '',
        'auto_append_file' => '',
    ];

    /**
     * `-d` arguments for {@see self::INI}.
     *
     * @return list<non-empty-string>
     */
    public static function args(): array
    {
        $args = [];
        foreach (self::INI as $name => $value) {
            $args[] = '-d';
            $args[] = "{$name}={$value}";
        }

        return $args;
    }

    /**
     * Short hash of everything besides the PHP version that changes the opcodes: the INI above and
     * the loaded Zend extensions.
     *
     * @return non-empty-string
     */
    public static function hash(PhpBinary $php): string
    {
        /** @var non-empty-string */
        return \substr(\hash('sha256', \serialize([self::INI, $php->zendExtensions])), 0, 12);
    }
}
