<?php

declare(strict_types=1);

namespace Opmin\Module\Runs;

use Internal\Path;

/**
 * One directory of `.opmin/runs/` as {@see RunIndex} found it.
 *
 * @internal
 */
final readonly class Run
{
    /**
     * @param non-empty-string $id The name of the directory: `Ymd-His`, `-N` for a second run of the same second.
     * @param bool $named Whether the name is a run id; other directories are listed and never deleted.
     * @param non-negative-int $size Bytes of every file in the directory.
     * @param bool $report Whether `report.json` is there.
     * @param bool $backup Whether `original/` is the only copy of the code it changed (not under git, not a
     *        dry run).
     * @param int $modified When the directory was last written (Unix time).
     */
    public function __construct(
        public string $id,
        public Path $dir,
        public bool $named,
        public RunKind $kind,
        public RunStatus $status,
        public ?int $opsBefore,
        public ?int $opsAfter,
        public int $size,
        public bool $report,
        public bool $backup,
        public int $modified,
    ) {}

    /**
     * `12 B`, `241 KB`, `3.4 MB`.
     */
    public static function bytes(int $size): string
    {
        return match (true) {
            $size < 1024 => "{$size} B",
            $size < 1024 * 1024 => \sprintf('%d KB', (int) \round($size / 1024)),
            default => \sprintf('%.1f MB', (float) $size / 1048576.0),
        };
    }

    /**
     * Whether something may still continue the run: an interrupted optimize run (any of them, not only the
     * newest, takes `--resume=<dir>`) or an open LLM session.
     */
    public function resumable(): bool
    {
        return $this->status === RunStatus::Interrupted || $this->status === RunStatus::Open;
    }
}
