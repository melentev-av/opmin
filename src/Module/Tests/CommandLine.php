<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Internal\Path;
use Opmin\Module\Php\PhpBinary;

/**
 * Turns a command of the config (`vendor/bin/phpstan analyse --no-progress`) into an argument list
 * run with `php.binary`: a PHP script of the project is not started through its shebang (that would
 * be the `php` on PATH, not the production one).
 *
 * @internal
 */
final class CommandLine
{
    /**
     * @return list<string>
     */
    public static function split(string $command): array
    {
        $args = [];
        $current = '';
        $quote = null;
        $started = false;
        $length = \strlen($command);
        for ($i = 0; $i < $length; ++$i) {
            $char = $command[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                } elseif ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                    $current .= $command[++$i];
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $started = true;
            } elseif (\ctype_space($char)) {
                $started and $args[] = $current;
                $current = '';
                $started = false;
            } else {
                $current .= $char;
                $started = true;
            }
        }

        $started and $args[] = $current;

        return $args;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    public static function withPhp(array $args, PhpBinary $php, Path $root): array
    {
        if ($args === []) {
            return $args;
        }

        $script = Path::create($args[0]);
        $script->isAbsolute() or $script = $root->join($args[0]);
        if ($script->isFile() && self::isPhpScript((string) $script)) {
            return [$php->path, (string) $script, ...\array_slice($args, 1)];
        }

        return $args;
    }

    private static function isPhpScript(string $file): bool
    {
        if (\str_ends_with($file, '.php')) {
            return true;
        }

        $head = (string) @\file_get_contents($file, false, null, 0, 128);

        return \str_starts_with($head, '<?php') || \preg_match('/^#!.*\bphp\b/', $head) === 1;
    }
}
