<?php

declare(strict_types=1);

namespace Opmin\Module\Runs;

use Internal\Path;
use Opmin\Module\Llm\Session;
use Opmin\Module\Optimize\RunState;

/**
 * The directories of `.opmin/runs/`, newest first, each with what wrote it and where it is
 * (`opmin runs`, `runs:show`, `runs:clean`, `runs.keep`).
 *
 * The kind is read from the files a run leaves: `llm.json` — an LLM session, `package.json` — a run of a
 * git package copied from its clone, `state.json` — `opmin optimize`, only `counterexamples/` —
 * `opmin verify`; nothing of that and no report — a broken run.
 *
 * @internal
 */
final class RunIndex
{
    /** Written by `optimize <git-url>` into every run it copies from the clone. */
    public const string PACKAGE_MARKER = 'package.json';

    private const string ID = '/^(\d{8}-\d{6})(?:-(\d+))?$/';

    /**
     * @param bool $underGit Whether the project is in a git work tree: then `original/` is not the only
     *        copy of the code (see {@see \Opmin\Module\Optimize\Workspace::gitRoot()}).
     * @return list<Run> Newest first.
     */
    public static function scan(Path $runsDir, bool $underGit): array
    {
        $entries = \is_dir((string) $runsDir) ? \scandir((string) $runsDir) : false;
        $runs = [];
        foreach ($entries === false ? [] : $entries as $name) {
            if ($name === '' || $name === '.' || $name === '..' || !\is_dir((string) $runsDir->join($name))) {
                continue;
            }

            $runs[] = self::read($runsDir->join($name), $name, $underGit);
        }

        \usort($runs, static fn(Run $a, Run $b): int => self::compare($b->id, $a->id));

        return $runs;
    }

    /**
     * The run of an id, or the newest one with a report when the id is null.
     *
     * @param list<Run> $runs
     */
    public static function find(array $runs, ?string $id): ?Run
    {
        foreach ($runs as $run) {
            if ($id === null ? $run->report : $run->id === $id) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Order of run ids: by the timestamp, then by the `-N` suffix as a number (`-10` after `-2`); a name that
     * is not an id comes before every id.
     */
    public static function compare(string $a, string $b): int
    {
        return self::sortKey($a) <=> self::sortKey($b);
    }

    /**
     * @param non-empty-string $id
     */
    private static function read(Path $dir, string $id, bool $underGit): Run
    {
        $report = self::json($dir->join('report.json'));
        $state = self::json($dir->join(RunState::FILE));
        [$kind, $status] = match (true) {
            $dir->join(Session::MARKER)->exists() => [
                RunKind::Llm,
                # An unreadable marker may still be a live session: keep it.
                (self::json($dir->join(Session::MARKER))['finished'] ?? null) !== null ? RunStatus::Finished : RunStatus::Open,
            ],
            $dir->join(self::PACKAGE_MARKER)->exists() => [RunKind::Package, $report === null ? RunStatus::Broken : RunStatus::Finished],
            $dir->join(RunState::FILE)->exists() => [
                RunKind::Optimize,
                # An unreadable state is kept like an interrupted run: it is not ours to judge.
                ($state['phase'] ?? null) === 'finished' ? RunStatus::Finished : RunStatus::Interrupted,
            ],
            $report !== null => [($report['kind'] ?? null) === 'llm' ? RunKind::Llm : RunKind::Optimize, RunStatus::Finished],
            $dir->join('counterexamples')->isDir() => [RunKind::Verify, RunStatus::Finished],
            default => [RunKind::Broken, RunStatus::Broken],
        };

        # A finished run without a patch left the code as it was: its original/ holds nothing to go back to.
        # An unfinished one may have stopped with a file half changed.
        $backup = !$underGit
            && $kind !== RunKind::Package
            && self::field($state, 'options', 'dry_run') !== true
            && ($status !== RunStatus::Finished || $dir->join('opmin.patch')->isFile())
            && self::hasFiles($dir->join('original'));
        /** @var mixed $before */
        $before = self::field($report, 'totals', 'ops_before');
        /** @var mixed $after */
        $after = self::field($report, 'totals', 'ops_after');

        return new Run(
            $id,
            $dir,
            \preg_match(self::ID, $id) === 1,
            $kind,
            $status,
            \is_int($before) ? $before : null,
            \is_int($after) ? $after : null,
            self::size($dir),
            $report !== null,
            $backup,
            (int) @\filemtime((string) $dir),
        );
    }

    /**
     * @return array{0: int, 1: string, 2: int}
     */
    private static function sortKey(string $id): array
    {
        return \preg_match(self::ID, $id, $m) === 1
            ? [1, $m[1], (int) ($m[2] ?? 1)]
            : [0, $id, 0];
    }

    /**
     * @return array<array-key, mixed>|null Null when the file is missing or not a JSON object.
     */
    private static function json(Path $file): ?array
    {
        if (!$file->isFile()) {
            return null;
        }

        /** @var mixed $data */
        $data = \json_decode((string) @\file_get_contents((string) $file), true);

        return \is_array($data) ? $data : null;
    }

    /**
     * `$data[$section][$key]`, whatever the JSON holds there; null when it is missing.
     *
     * @param array<array-key, mixed>|null $data
     */
    private static function field(?array $data, string $section, string $key): mixed
    {
        /** @var mixed $value */
        $value = $data[$section] ?? null;

        return \is_array($value) ? ($value[$key] ?? null) : null;
    }

    private static function hasFiles(Path $dir): bool
    {
        if (!$dir->isDir()) {
            return false;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $_) {
            return true;
        }

        return false;
    }

    /**
     * @return non-negative-int
     */
    private static function size(Path $dir): int
    {
        $size = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $dir, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $file->isFile() and $size += \max(0, (int) $file->getSize());
        }

        return $size;
    }
}
