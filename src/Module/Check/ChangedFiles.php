<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

use Internal\Path;
use Symfony\Component\Process\Process;

/**
 * Files changed relative to the base ref: `git diff --name-only <base>` (committed and uncommitted
 * changes) plus untracked files.
 *
 * Renames are listed as a deletion and an addition, so the functions of the old path are looked at
 * too: a function that moved keeps its key, one that disappeared is reported as removed.
 *
 * @internal
 */
final class ChangedFiles
{
    /**
     * @param non-empty-string $base
     * @return list<non-empty-string> Paths relative to `$root`, with `/` separators, sorted.
     * @throws BaselineException When `$root` is not in a git work tree or the base ref is unknown.
     */
    public static function since(Path $root, string $base): array
    {
        $inside = self::git($root, ['rev-parse', '--is-inside-work-tree']);
        $inside->isSuccessful() && \trim($inside->getOutput()) === 'true' or throw new BaselineException(
            "{$root} is not a git work tree: the changed files cannot be determined. Run `opmin check --all`.",
        );

        $verify = self::git($root, ['rev-parse', '--verify', '--quiet', $base . '^{commit}']);
        $verify->isSuccessful() or throw new BaselineException(\sprintf(
            'The base ref `%s` is not known to git. Fetch it (in GitHub Actions: actions/checkout with '
            . '`fetch-depth: 0`; in GitLab CI: `GIT_DEPTH: 0`) or pass another one with --base= / check.base_ref.',
            $base,
        ));

        $diff = self::git($root, ['diff', '--name-only', '--no-renames', '--relative', '-z', $base, '--']);
        $diff->isSuccessful() or throw new BaselineException("git diff {$base} failed: " . \trim($diff->getErrorOutput()));
        $untracked = self::git($root, ['ls-files', '--others', '--exclude-standard', '-z', '--', '.']);
        $untracked->isSuccessful() or throw new BaselineException('git ls-files failed: ' . \trim($untracked->getErrorOutput()));

        $files = \array_filter(
            \explode("\0", $diff->getOutput() . $untracked->getOutput()),
            static fn(string $file): bool => $file !== '',
        );
        $files = \array_values(\array_unique($files));
        \sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $args
     */
    private static function git(Path $root, array $args): Process
    {
        # `-z` everywhere: paths with spaces or non-ASCII characters come unquoted.
        $process = new Process(['git', ...$args], (string) $root);
        $process->run();

        return $process;
    }
}
