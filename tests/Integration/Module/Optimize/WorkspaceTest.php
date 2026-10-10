<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Optimize;

use Internal\Path;
use Opmin\Module\Config\Schema\RequireClean;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Project\Project;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The clean-tree check and the git commands of a run against a real repository: the user's
 * changes in files the run does not touch survive every one of them.
 */
#[Test]
#[Covers(Workspace::class)]
final class WorkspaceTest
{
    private string $dir = '';

    /** The project root, the repository root or a package directory in it. */
    private string $root = '';

    #[BeforeTest]
    public function createRepository(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-workspace-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
        $this->git('init', '-q');
        $this->git('config', 'user.name', 't');
        $this->git('config', 'user.email', 't@t');
        $this->git('config', 'core.autocrlf', 'false');
    }

    #[AfterTest]
    public function removeRepository(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    #[DataSet([''], 'project at the repository root')]
    #[DataSet(['packages/http'], 'package of a monorepo')]
    public function targetsModeLetsOtherFilesStayDirty(string $package): void
    {
        $this->commitProject($package);
        $this->dirtyOthers();

        Workspace::create($this->project(), $this->runDir(), false, [], RequireClean::Targets, ['src/A.php']);

        Assert::true(\is_dir((string) $this->runDir()));
    }

    #[DataSet([''], 'project at the repository root')]
    #[DataSet(['packages/http'], 'package of a monorepo')]
    public function targetsModeListsOnlyTheDirtyTargets(string $package): never
    {
        $this->commitProject($package);
        $this->dirtyOthers();
        \file_put_contents($this->root . '/src/A.php', "<?php // changed\n");

        Expect::exception(\RuntimeException::class)->withMessage(
            "Files of the run have uncommitted changes: commit or stash them first (every accepted step becomes a commit of its files).\nsrc/A.php",
        );

        Workspace::create($this->project(), $this->runDir(), false, [], RequireClean::Targets, ['src/A.php', 'src/B.php']);
    }

    public function allModeRefusesAnyDirtyFile(): never
    {
        $this->commitProject('');
        \file_put_contents($this->root . '/composer.json', "{\"name\": \"changed\"}\n");

        Expect::exception(\RuntimeException::class)->withMessageContaining("The git working tree is not clean")->withMessageContaining('composer.json');

        Workspace::create($this->project(), $this->runDir(), false, [], RequireClean::All, ['src/A.php']);
    }

    public function offModeChecksNothing(): void
    {
        $this->commitProject('');
        \file_put_contents($this->root . '/src/A.php', "<?php // changed\n");

        $workspace = Workspace::create($this->project(), $this->runDir(), false, [], RequireClean::Off, ['src/A.php']);

        Assert::true($workspace->git());
    }

    public function commitTakesOnlyTheFilesOfTheStep(): void
    {
        $this->commitProject('');
        $this->dirtyOthers();
        $workspace = Workspace::create($this->project(), $this->runDir(), false, ['runs'], RequireClean::Targets, ['src/A.php']);

        $workspace->write(Path::create($this->root . '/src/A.php'), "<?php // optimized\n");
        $workspace->commit([Path::create($this->root . '/src/A.php')], 'opmin: step');

        Assert::same($this->git('show', '--name-only', '--format=', 'HEAD'), "src/A.php\n");
        $this->assertOthersUntouched();
    }

    #[DataSet([''], 'project at the repository root')]
    #[DataSet(['packages/http'], 'package of a monorepo')]
    public function rewindRestoresOnlyTheFilesOfTheDroppedCommits(string $package): void
    {
        $this->commitProject($package);
        $head = \trim($this->git('rev-parse', 'HEAD'));
        # Commits of a run that crashed before it saved its state: a step and a new baseline file.
        \file_put_contents($this->root . '/src/A.php', "<?php // optimized\n");
        \file_put_contents($this->root . '/opmin.baseline.yaml', "rejected: []\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'opmin: step');
        $this->dirtyOthers();

        Workspace::rewind($this->project(), $head);

        Assert::same(\trim($this->git('rev-parse', 'HEAD')), $head);
        Assert::same(\file_get_contents($this->root . '/src/A.php'), "<?php\n");
        Assert::false(\is_file($this->root . '/opmin.baseline.yaml'));
        $this->assertOthersUntouched();
    }

    public function rewindRefusesCommitsOfOthers(): never
    {
        $this->commitProject('');
        $head = \trim($this->git('rev-parse', 'HEAD'));
        \file_put_contents($this->root . '/src/B.php', "<?php // by hand\n");
        $this->git('commit', '-q', '-am', 'feat: by hand');

        Expect::exception(\RuntimeException::class)->withMessageContaining('HEAD moved since the run was interrupted');

        Workspace::rewind($this->project(), $head);
    }

    /**
     * A committed project with two target files and a composer.json, in a subdirectory of the
     * repository for a package.
     */
    private function commitProject(string $package): void
    {
        $this->root = $package === '' ? $this->dir : $this->dir . '/' . $package;
        \mkdir($this->root . '/src', 0777, true);
        \file_put_contents($this->root . '/src/A.php', "<?php\n");
        \file_put_contents($this->root . '/src/B.php', "<?php\n");
        \file_put_contents($this->root . '/composer.json', "{}\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'init');
    }

    private function project(): Project
    {
        return new Project(Path::create($this->root), true, null);
    }

    private function runDir(): Path
    {
        return Path::create($this->root . '/runs/1');
    }

    /**
     * What a user has in the tree after `opmin init` and some work of their own.
     */
    private function dirtyOthers(): void
    {
        \file_put_contents($this->root . '/composer.json', "{\"name\": \"changed\"}\n");
        \file_put_contents($this->root . '/opmin.yaml', "paths: [src]\n");
        \file_put_contents($this->dir . '/staged.txt', "staged\n");
        $this->git('add', 'staged.txt');
    }

    private function assertOthersUntouched(): void
    {
        Assert::same(\file_get_contents($this->root . '/composer.json'), "{\"name\": \"changed\"}\n");
        Assert::same(\file_get_contents($this->root . '/opmin.yaml'), "paths: [src]\n");
        Assert::same($this->git('diff', '--cached', '--name-only'), "staged.txt\n");
        Assert::string($this->git('status', '--porcelain'))->contains('composer.json')->contains('opmin.yaml');
    }

    private function git(string ...$args): string
    {
        $out = [];
        \exec('git -C ' . \escapeshellarg($this->dir) . ' ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        $code === 0 or throw new \RuntimeException('git ' . \implode(' ', $args) . ': ' . \implode("\n", $out));

        return $out === [] ? '' : \implode("\n", $out) . "\n";
    }
}
