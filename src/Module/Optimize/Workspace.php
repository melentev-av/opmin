<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Project\Project;
use Symfony\Component\Process\Process;

/**
 * Where an optimization run changes files and how it keeps every step reversible (brief,
 * «Команда `opmin optimize`», «Целевая кодовая база»):
 *
 * - a git working tree (the project's files are tracked) must be clean; every accepted step is a
 *   commit of the files it changed;
 * - outside git, the originals are copied to `runs/<ts>/original/` before the first change;
 * - with `--dry-run` nothing stays changed: the originals are restored at the end.
 *
 * In every mode the originals are backed up and the run ends with `runs/<ts>/opmin.patch`.
 *
 * @internal
 */
final class Workspace
{
    /** @var array<non-empty-string, string> Relative path => original content. */
    private array $originals = [];

    private function __construct(
        private readonly Project $project,
        public readonly Path $runDir,
        private readonly ?Path $gitRoot,
        public readonly bool $dryRun,
    ) {}

    /**
     * @param list<non-empty-string> $ignored Relative paths whose changes do not make the tree dirty
     *        (the run directory, the cache).
     * @throws \RuntimeException When the git working tree is not clean.
     */
    public static function create(Project $project, Path $runDir, bool $dryRun, array $ignored = []): self
    {
        $top = new Process(['git', 'rev-parse', '--show-toplevel'], (string) $project->root);
        $top->run();
        $gitRoot = $top->isSuccessful() && \trim($top->getOutput()) !== '' ? Path::create(\trim($top->getOutput())) : null;
        if ($gitRoot !== null) {
            # A project in an ignored directory of another repository is not under git.
            $tracked = new Process(['git', 'ls-files', '--', '.'], (string) $project->root);
            $tracked->run();
            \trim($tracked->getOutput()) === '' and $gitRoot = null;
        }
        $workspace = new self($project, $runDir, $gitRoot, $dryRun);
        # A dry run commits nothing and restores every file: the tree may be dirty.
        $gitRoot === null || $dryRun or $workspace->ensureClean($ignored);
        FS::mkdir((string) $runDir);

        return $workspace;
    }

    public function git(): bool
    {
        return $this->gitRoot !== null;
    }

    public function mode(): string
    {
        return ($this->git() ? 'git: a commit per accepted step' : 'copy: originals in ' . $this->project->relative($this->runDir->join('original')))
            . ($this->dryRun ? ', dry run' : '');
    }

    /**
     * Writes a file, backing up its original first.
     */
    public function write(Path $file, string $content): void
    {
        $this->backup($file);
        \file_put_contents((string) $file, $content);
    }

    /**
     * Copies the original of a file to `runs/<ts>/original/` before anything touches it.
     *
     * @param string|null $original The original content when the file on disk is already changed
     *        (by Rector); null — read it from disk.
     */
    public function backup(Path $file, ?string $original = null): void
    {
        $relative = $this->project->relative($file);
        if (isset($this->originals[$relative])) {
            return;
        }

        $original ??= (string) \file_get_contents((string) $file);
        $this->originals[$relative] = $original;
        $backup = $this->runDir->join('original', $relative);
        FS::mkdir((string) $backup->parent());
        \file_put_contents((string) $backup, $original);
    }

    /**
     * Commits the files of an accepted step (git, not a dry run).
     *
     * @param list<Path> $files
     * @return string|null The commit hash.
     * @throws \RuntimeException When git refuses.
     */
    public function commit(array $files, string $message): ?string
    {
        if ($this->gitRoot === null || $this->dryRun || $files === []) {
            return null;
        }

        $paths = \array_map('strval', $files);
        $this->runGit(['add', '--', ...$paths]);
        # The project's hooks (linters, commit message rules) are for people, not for each step.
        $this->runGit(['commit', '--no-verify', '--quiet', '-m', $message, '--', ...$paths]);
        return \trim($this->runGit(['rev-parse', 'HEAD']));
    }

    /**
     * Relative paths of the files changed so far, with their original content.
     *
     * @return array<non-empty-string, string>
     */
    public function originals(): array
    {
        return $this->originals;
    }

    /**
     * Ends the run: writes the patch of all changes; a dry run restores the originals.
     *
     * @return Path|null The patch; null when nothing changed.
     */
    public function finish(): ?Path
    {
        $patch = '';
        foreach ($this->originals as $relative => $original) {
            $current = (string) @\file_get_contents((string) $this->project->root->join($relative));
            if ($current === $original) {
                continue;
            }

            $hunks = (new LineDiff($original, $current))->unified();
            $patch .= "--- a/{$relative}\n+++ b/{$relative}\n{$hunks}";
        }

        if ($this->dryRun) {
            foreach ($this->originals as $relative => $original) {
                \file_put_contents((string) $this->project->root->join($relative), $original);
            }
        }

        if ($patch === '') {
            return null;
        }

        $file = $this->runDir->join('opmin.patch');
        \file_put_contents((string) $file, $patch);

        return $file;
    }

    /**
     * @param list<non-empty-string> $ignored
     */
    private function ensureClean(array $ignored): void
    {
        $status = $this->runGit(['status', '--porcelain', '--untracked-files=all', '--', '.']);
        $prefix = $this->gitRoot === null ? '' : (string) $this->project->root->tryRelative($this->gitRoot);
        $prefix = $prefix === '' || $prefix === '.' ? '' : \rtrim(\str_replace('\\', '/', $prefix), '/') . '/';
        $dirty = [];
        foreach (\explode("\n", \rtrim($status)) as $line) {
            if ($line === '') {
                continue;
            }

            $path = \substr($line, 3);
            \str_contains($path, ' -> ') and $path = \substr($path, (int) \strpos($path, ' -> ') + 4);
            $path = \trim($path, '"');
            $relative = \str_starts_with($path, $prefix) ? \substr($path, \strlen($prefix)) : $path;
            foreach ($ignored as $skip) {
                if ($relative === $skip || \str_starts_with($relative, \rtrim($skip, '/') . '/')) {
                    continue 2;
                }
            }

            $dirty[] = $relative;
        }

        $dirty === [] or throw new \RuntimeException(
            "The git working tree is not clean: commit or stash first (every accepted step becomes a commit).\n"
            . \implode("\n", \array_slice($dirty, 0, 10)),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runGit(array $args): string
    {
        $process = new Process(['git', ...$args], (string) $this->project->root);
        $process->run();
        $process->isSuccessful() or throw new \RuntimeException('git ' . ($args[0] ?? '') . ' failed: ' . \trim($process->getErrorOutput() . $process->getOutput()));

        return $process->getOutput();
    }
}
