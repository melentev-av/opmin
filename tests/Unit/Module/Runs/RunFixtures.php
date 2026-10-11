<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Runs;

/**
 * Run directories as the commands of opmin leave them, built from a list of files.
 */
final class RunFixtures
{
    public const FINISHED_OPTIMIZE = [
        'state.json' => '{"version": 1, "phase": "finished", "options": {"dry_run": false}}',
        'report.json' => '{"schema": 1, "kind": "optimize", "totals": {"ops_before": 442, "ops_after": 372}}',
        'report.md' => "# optimize\n",
    ];
    public const INTERRUPTED_OPTIMIZE = ['state.json' => '{"version": 1, "phase": "steps"}', 'original/src/A.php' => '<?php'];
    public const CLOSING_OPTIMIZE = ['state.json' => '{"version": 1, "phase": "closing"}'];
    public const OPEN_LLM = ['llm.json' => '{"finished": null, "targets": []}'];
    public const FINISHED_LLM = [
        'llm.json' => '{"finished": "2026-10-11T10:00:00+00:00", "targets": []}',
        'report.json' => '{"schema": 1, "kind": "llm", "totals": {"ops_before": 100, "ops_after": 90}}',
        'report.md' => "# llm\n",
    ];
    public const VERIFY = ['counterexamples/A_slugTest.php' => '<?php'];
    public const PACKAGE = [
        'package.json' => '{"package": "acme-text", "ref": null, "run": "20261011-100000"}',
        'state.json' => '{"version": 1, "phase": "finished"}',
        'report.json' => '{"schema": 1, "kind": "optimize", "totals": {"ops_before": 5, "ops_after": 4}}',
        'report.md' => "# package\n",
        'original/src/A.php' => '<?php',
    ];

    /** An optimize run that failed on git before it saved its state (reproduced in #48). */
    public const BROKEN = ['00-baseline.json' => '{}', 'original/src/A.php' => '<?php', 'symbols.json' => '{}'];

    /** Outside git, not a dry run: `original/` is the only copy of the code. */
    public const BACKUP = [
        'state.json' => '{"version": 1, "phase": "finished", "options": {"dry_run": false}}',
        'report.json' => '{"schema": 1, "kind": "optimize", "totals": {"ops_before": 9, "ops_after": 8}}',
        'report.md' => "# backup\n",
        'original/src/A.php' => '<?php',
    ];

    public const DRY_RUN = [
        'state.json' => '{"version": 1, "phase": "finished", "options": {"dry_run": true}}',
        'report.json' => '{"schema": 1, "kind": "optimize", "totals": {"ops_before": 9, "ops_after": 8}}',
        'report.md' => "# dry run\n",
        'original/src/A.php' => '<?php',
    ];

    /**
     * Writes `<dir>/<id>/<file>` for every file; the run is dated `$modified` (Unix time).
     *
     * @param array<non-empty-string, string> $files
     */
    public static function write(string $runsDir, string $id, array $files, ?int $modified = null): string
    {
        $dir = "{$runsDir}/{$id}";
        @\mkdir($dir, 0777, true);
        foreach ($files as $file => $content) {
            @\mkdir(\dirname("{$dir}/{$file}"), 0777, true);
            \file_put_contents("{$dir}/{$file}", $content);
        }

        # A day old by default: not a run that may still be starting.
        \touch($dir, $modified ?? \time() - 86400);

        return $dir;
    }

    public static function tempDir(string $prefix): string
    {
        $dir = (string) \realpath(\sys_get_temp_dir()) . "/{$prefix}-" . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/.opmin/runs', 0777, true);

        return $dir;
    }

    public static function remove(string $dir): void
    {
        $dir === '' or \exec('rm -rf ' . \escapeshellarg($dir));
    }
}
