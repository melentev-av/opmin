<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Project;

use Internal\Path;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Project::class)]
#[Covers(FileFinder::class)]
final class ProjectTest
{
    private string $dir;

    public static function constraints(): iterable
    {
        yield 'platform wins' => ['{"require": {"php": ">=8.1"}, "config": {"platform": {"php": "8.3.16"}}}', '8.3'];
        yield 'lowest of alternatives' => ['{"require": {"php": "^8.4 || ^8.2"}}', '8.2'];
        yield 'range' => ['{"require": {"php": ">=8.1 <8.5"}}', '8.1'];
        yield 'no php' => ['{"require": {}}', null];
        yield 'broken json' => ['{', null];
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-project-' . \bin2hex(\random_bytes(4));
        foreach (['src/A.php', 'src/sub/B.php', 'src/sub/notes.txt', 'src/vendor/C.php', 'src/Legacy/D.php', 'tests/T.php'] as $file) {
            @\mkdir(\dirname("{$this->dir}/{$file}"), 0777, true);
            \file_put_contents("{$this->dir}/{$file}", '<?php');
        }
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    #[DataProvider('constraints')]
    public function readsPhpTargetFromComposer(string $composer, ?string $target): void
    {
        \file_put_contents("{$this->dir}/composer.json", $composer);

        $project = Project::detect(Path::create("{$this->dir}/src/sub"), Path::create('/'));

        Assert::same((string) $project->root, $this->dir);
        Assert::true($project->composer);
        Assert::same($project->phpTarget, $target);
    }

    public function fallsBackWithoutComposer(): void
    {
        $project = Project::detect(Path::create("{$this->dir}/src"), Path::create("{$this->dir}/src"));

        Assert::false($project->composer);
        Assert::same($project->relative(Path::create("{$this->dir}/src/sub/B.php")), 'sub/B.php');
    }

    public function findsPhpFilesAndAppliesExclusions(): void
    {
        $project = new Project(Path::create($this->dir), true, null);

        $files = (new FileFinder())->find($project, [Path::create($this->dir)], ['vendor', 'tests', 'src/Legacy']);

        Assert::same(\array_map($project->relative(...), $files), ['src/A.php', 'src/sub/B.php']);
    }

    public function anExclusionWithoutASlashIgnoresTheCaseAndTakesWildcards(): void
    {
        \mkdir("{$this->dir}/src/Tests");
        \file_put_contents("{$this->dir}/src/Tests/ATest.php", '<?php');
        \file_put_contents("{$this->dir}/src/sub/BTest.php", '<?php');
        $project = new Project(Path::create($this->dir), true, null);

        $files = (new FileFinder())->find($project, [Path::create("{$this->dir}/src")], ['tests', 'vendor', 'Legacy', '*Test.php']);

        Assert::same(\array_map($project->relative(...), $files), ['src/A.php', 'src/sub/B.php']);
    }

    public function exclusionsApplyOnlyBelowAGivenPath(): void
    {
        $project = new Project(Path::create($this->dir), true, null);

        $files = (new FileFinder())->find($project, [Path::create("{$this->dir}/src/Legacy"), Path::create("{$this->dir}/tests/T.php")], ['tests', 'src/Legacy']);

        Assert::same(\array_map($project->relative(...), $files), ['src/Legacy/D.php', 'tests/T.php']);
    }
}
