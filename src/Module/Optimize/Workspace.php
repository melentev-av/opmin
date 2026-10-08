<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Config\Schema\RequireClean;
use Opmin\Module\Project\Project;
use Symfony\Component\Process\Process;

/**
 * Where an optimization run changes files and how it keeps every step reversible (brief,
 * «Команда `opmin optimize`», «Целевая кодовая база»):
 *
 * - in a git working tree (the project's files are tracked) every accepted step is a commit of the
 *   files it changed, so those must be clean (`git.require_clean`); other files may stay dirty, and
 *   every git command that changes the tree names its paths;
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
     * @param list<non-empty-string> $targets Relative paths of the files the run may change: with
     *        {@see RequireClean::Targets} only they must be clean.
     * @throws \RuntimeException When the git working tree is not clean.
     */
    public static function create(
        Project $project,
        Path $runDir,
        bool $dryRun,
        array $ignored = [],
        RequireClean $requireClean = RequireClean::All,
        array $targets = [],
    ): self {
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
        $gitRoot === null || $dryRun || $requireClean === RequireClean::Off
            or $workspace->ensureClean($ignored, $requireClean === RequireClean::Targets ? $targets : null);
        FS::mkdir((string) $runDir);
        $workspace->loadOriginals();

        return $workspace;
    }

    /**
     * Brings a resumed run's git tree back to the commit of its last saved step. Only commits of
     * opmin made after it are dropped (a crash between a commit and the save of the state); anything
     * else that moved HEAD stops the resume. Only the files of the dropped commits are restored:
     * other changes in the tree, staged or not, stay.
     *
     * @throws \RuntimeException When HEAD moved otherwise.
     */
    public static function rewind(Project $project, string $head): void
    {
        $git = static function (array $args, ?string $input = null) use ($project): Process {
            $process = new Process(['git', ...$args], (string) $project->root, null, $input);
            $process->run();

            return $process;
        };
        $current = \trim($git(['rev-parse', 'HEAD'])->getOutput());
        if ($current === $head) {
            return;
        }

        $moved = "HEAD moved since the run was interrupted (it was {$head}, now {$current}): check that commit out or start a new run.";
        $git(['merge-base', '--is-ancestor', $head, $current])->isSuccessful() or throw new \RuntimeException($moved);
        foreach (\explode("\n", \trim($git(['log', '--format=%s', "{$head}..{$current}"])->getOutput())) as $subject) {
            \str_starts_with($subject, 'opmin: ') or throw new \RuntimeException($moved);
        }

        # Not `reset --hard`: it would also throw away the user's changes in files opmin never touched.
        $paths = $git(['diff', '--name-only', '-z', '--relative', $head, $current])->getOutput();
        $steps = [['reset', '--soft', '--quiet', $head]];
        $paths === '' or $steps[] = ['restore', "--source={$head}", '--staged', '--worktree', '--pathspec-from-file=-', '--pathspec-file-nul'];
        foreach ($steps as $args) {
            $process = $git($args, $args[0] === 'restore' ? $paths : null);
            $process->isSuccessful() or throw new \RuntimeException("git {$args[0]} failed: " . \trim($process->getErrorOutput()));
        }
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
        FS::replace((string) $file, $content);
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
     * The commit the working tree is on; null outside git.
     */
    public function head(): ?string
    {
        if ($this->gitRoot === null) {
            return null;
        }

        $head = \trim($this->runGit(['rev-parse', 'HEAD']));

        return $head === '' ? null : $head;
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
                FS::replace((string) $this->project->root->join($relative), $original);
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
     * A run continued by another process (the calls of the LLM stage) keeps the originals backed up
     * by the earlier ones.
     */
    private function loadOriginals(): void
    {
        $dir = $this->runDir->join('original');
        if (!\is_dir((string) $dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $dir, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $relative = \str_replace('\\', '/', \substr($file->getPathname(), \strlen((string) $dir) + 1));
            $relative === '' or $this->originals[$relative] = (string) \file_get_contents($file->getPathname());
        }
    }

    /**
     * @param list<non-empty-string> $ignored
     * @param list<non-empty-string>|null $only The relative paths to check; null — the whole tree.
     */
    private function ensureClean(array $ignored, ?array $only): void
    {
        $only === null or $only = \array_fill_keys($only, true);
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
            if ($only !== null && !isset($only[$relative])) {
                continue;
            }

            foreach ($ignored as $skip) {
                if ($relative === $skip || \str_starts_with($relative, \rtrim($skip, '/') . '/')) {
                    continue 2;
                }
            }

            $dirty[] = $relative;
        }

        $dirty === [] or throw new \RuntimeException(
            ($only === null
                ? 'The git working tree is not clean: commit or stash first (every accepted step becomes a commit).'
                : 'Files of the run have uncommitted changes: commit or stash them first (every accepted step becomes a commit of its files).')
            . "\n" . \implode("\n", \array_slice($dirty, 0, 10)),
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
