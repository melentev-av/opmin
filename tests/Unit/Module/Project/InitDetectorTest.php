<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Project;

use Internal\Path;
use Opmin\Module\Project\InitDetector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InitDetector::class)]
final class InitDetectorTest
{
    private string $dir;

    public static function layouts(): iterable
    {
        yield 'code directories win' => [['src', 'lib', 'Foo'], '{"autoload": {"psr-4": {"A\\\\": "Foo/"}}}', ['src', 'lib']];
        yield 'namespace in the root' => [['Tests'], '{"autoload": {"psr-4": {"Symfony\\\\Component\\\\String\\\\": ""}}}', ['.']];
        yield 'root covers the rest' => [['Bar'], '{"autoload": {"psr-4": {"A\\\\": ["", "Bar/"]}}}', ['.']];
        yield 'psr-0 and classmap' => [['Foo', 'Legacy'], '{"autoload": {"psr-0": {"A_": "Foo/"}, "classmap": ["Legacy/", "Missing/"]}}', ['Foo', 'Legacy']];
        yield 'autoload-dev is not code' => [['Fixtures'], '{"autoload-dev": {"psr-4": {"T\\\\": "Fixtures/"}}}', null];
        yield 'broken json' => [[], '{', null];
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-init-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    /**
     * @param list<string> $dirs
     * @param list<string>|null $paths
     */
    #[DataProvider('layouts')]
    public function detectsTheCodePaths(array $dirs, string $composer, ?array $paths): void
    {
        foreach ($dirs as $dir) {
            \mkdir("{$this->dir}/{$dir}");
        }

        \file_put_contents("{$this->dir}/composer.json", $composer);

        $found = InitDetector::detect(Path::create($this->dir));

        Assert::same($found['paths'][0] ?? null, $paths);
    }
}
