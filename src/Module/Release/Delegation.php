<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

use Internal\Path;

/**
 * A global opmin (the binary or a PHAR in PATH) started inside a project that has its own opmin hands the
 * run over to it, so the version the project pinned is the one that runs: `vendor/bin/opmin` (the composer
 * package with the PHAR) or `opmin` in the project root (the binary dload downloads).
 *
 * `OPMIN_NO_DELEGATE=1` keeps the global one; the local one is started with `OPMIN_DELEGATED=1`, so it never
 * hands over again.
 *
 * @internal
 */
final class Delegation
{
    /** @var list<non-empty-string> Relative to the project root, in order of preference. */
    public const CANDIDATES = ['vendor/bin/opmin', 'opmin'];

    /** @var list<non-empty-string> Files that mark the project root. */
    private const ROOT_MARKS = ['composer.json', 'opmin.yaml', 'opmin.yaml.dist', ProjectVersion::FILE];

    /**
     * The local opmin to hand over to, or null to run this one.
     *
     * @param array<string, string> $env
     */
    public static function target(Installation $installation, Path $cwd, array $env): ?Path
    {
        if ($installation->kind === Installation::SOURCES || $installation->file === null
            || ($env['OPMIN_NO_DELEGATE'] ?? '') !== '' || ($env['OPMIN_DELEGATED'] ?? '') !== '') {
            return null;
        }

        $root = self::root($cwd);
        if ($root === null) {
            return null;
        }

        $self = \realpath((string) $installation->file);
        foreach (self::CANDIDATES as $candidate) {
            $file = $root->join($candidate);
            $real = \realpath((string) $file);
            if ($real !== false && \is_file($real) && \is_executable($real) && $real !== $self) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Runs the local opmin with the same arguments and returns its exit code.
     *
     * @param list<string> $arguments Without the program name.
     * @param array<string, string> $env
     */
    public static function run(Path $target, array $arguments, array $env): int
    {
        $process = \proc_open(
            [(string) $target, ...$arguments],
            [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR],
            $pipes,
            null,
            ['OPMIN_DELEGATED' => '1'] + $env,
        );
        if (!\is_resource($process)) {
            \fwrite(\STDERR, "opmin: cannot start the project's {$target}; set OPMIN_NO_DELEGATE=1 to use the global opmin.\n");
            return 1;
        }

        return \proc_close($process);
    }

    private static function root(Path $cwd): ?Path
    {
        $dir = $cwd;
        while (true) {
            foreach (self::ROOT_MARKS as $mark) {
                if ($dir->join($mark)->isFile()) {
                    return $dir;
                }
            }

            $parent = $dir->parent();
            if ((string) $parent === (string) $dir) {
                return null;
            }

            $dir = $parent;
        }
    }
}
