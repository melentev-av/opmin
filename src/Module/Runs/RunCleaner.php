<?php

declare(strict_types=1);

namespace Opmin\Module\Runs;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Project\Layout;

/**
 * Which runs `opmin runs:clean --keep=N` (and `runs.keep` after a new run) deletes:
 *
 * - kept: the N newest finished runs; every interrupted optimize run and every open LLM session (each
 *   can still be continued); the run being started; a directory whose name is not a run id;
 * - kept: a broken run written in the last hour — it may be a run that has not saved its state yet;
 * - kept unless `--include-backups`: a run whose `original/` is the only copy of the code it changed
 *   (outside git, not a dry run);
 * - deleted: everything else, with its copy of the report `.opmin/report_<id>.md`.
 *
 * @internal
 */
final class RunCleaner
{
    /** How long a broken run may be one that is still starting. */
    private const int STARTING = 3600;

    /**
     * @param list<Run> $runs Newest first, as {@see RunIndex::scan()} gives them.
     * @param non-negative-int $keep
     * @param Path|null $current The run being started: never deleted.
     */
    public static function plan(array $runs, int $keep, bool $includeBackups, ?Path $current = null, ?int $now = null): CleanPlan
    {
        $now ??= \time();
        $delete = [];
        $backups = [];
        $finished = 0;
        foreach ($runs as $run) {
            if (!$run->named || $run->resumable() || ($current !== null && (string) $run->dir === (string) $current)) {
                continue;
            }

            if ($run->status === RunStatus::Broken) {
                if ($now - $run->modified < self::STARTING) {
                    continue;
                }
            } elseif (++$finished <= $keep) {
                continue;
            }

            if ($run->backup && !$includeBackups) {
                $backups[] = $run;
                continue;
            }

            $delete[] = $run;
        }

        return new CleanPlan($delete, $backups);
    }

    /**
     * Deletes the runs of a plan with their copies of the report.
     *
     * @return list<non-empty-string> What could not be deleted, with why.
     */
    public static function apply(CleanPlan $plan): array
    {
        $errors = [];
        foreach ($plan->delete as $run) {
            try {
                FS::removeDir($run->dir) or throw new \RuntimeException('not removed');
                $copy = Layout::report($run->dir);
                $copy === null || !$copy->isFile() or FS::removeFile($copy) or throw new \RuntimeException("{$copy} not removed");
            } catch (\Throwable $e) {
                $errors[] = "{$run->dir}: {$e->getMessage()}";
            }
        }

        return $errors;
    }

    /**
     * `runs.keep` right after a run directory is created: the same rules as `runs:clean` without
     * `--include-backups`. Never fails the run.
     *
     * @param int|null $keep `runs.keep`; null — off.
     * @return array{0: list<Run>, 1: list<non-empty-string>} The deleted runs and the warnings.
     */
    public static function prune(?int $keep, Path $runsDir, Path $current, bool $underGit): array
    {
        if ($keep === null) {
            return [[], []];
        }

        if ($keep < 0) {
            return [[], ["runs.keep must be 0 or more, got {$keep}: old runs are not pruned."]];
        }

        try {
            $plan = self::plan(RunIndex::scan($runsDir, $underGit), $keep, false, $current);
            $errors = self::apply($plan);
        } catch (\Throwable $e) {
            return [[], ['runs.keep: old runs are not pruned: ' . $e->getMessage()]];
        }

        return [
            $plan->delete,
            $errors === [] ? [] : ['runs.keep: some old runs are not deleted: ' . \implode('; ', $errors)],
        ];
    }
}
