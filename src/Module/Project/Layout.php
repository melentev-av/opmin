<?php

declare(strict_types=1);

namespace Opmin\Module\Project;

use Internal\Path;

/**
 * Where opmin writes in a project: everything under one directory next to the config, so a single
 * `.gitignore` line (`opmin init` adds it) keeps it out of the repository.
 *
 * @internal
 */
final class Layout
{
    /** The directory of everything opmin writes. */
    public const string DIR = '.opmin';

    /** One directory per run: the report, the patch, the counterexamples, the state of `--resume`. */
    public const string RUNS = self::DIR . '/runs';

    /** The default `cache.dir`. */
    public const string CACHE = self::DIR . '/cache';

    /** The line added to `.gitignore`: at any depth, so a package of a monorepo is covered too. */
    public const string GITIGNORE = self::DIR . '/';

    /**
     * `.opmin/report_<ts>.md`: the report of a run where it is found without opening `runs/`; null for a run
     * directory outside `.opmin/runs/`.
     */
    public static function report(Path $runDir): ?Path
    {
        $runs = $runDir->parent();
        $dir = $runs->parent();
        if ($runs->name() !== 'runs' || $dir->name() !== self::DIR) {
            return null;
        }

        return $dir->join('report_' . $runDir->name() . '.md');
    }

    /**
     * Adds {@see self::GITIGNORE} to the `.gitignore` of a git work tree when no line covers it yet.
     *
     * @param Path $dir The directory of the config: the root of the work tree or a package in it.
     * @return bool Whether the line was added.
     */
    public static function ignore(Path $dir): bool
    {
        $gitignore = $dir->join('.gitignore');
        if (!$gitignore->exists() && !$dir->join('.git')->exists()) {
            return false;
        }

        $content = $gitignore->exists() ? (string) @\file_get_contents((string) $gitignore) : '';
        $lines = \array_map(static fn(string $line): string => \trim($line, " \t\r/"), \explode("\n", $content));
        if (\in_array(self::DIR, $lines, true)) {
            return false;
        }

        $prefix = $content === '' || \str_ends_with($content, "\n") ? '' : "\n";

        return @\file_put_contents((string) $gitignore, $prefix . self::GITIGNORE . "\n", \FILE_APPEND) !== false;
    }
}
