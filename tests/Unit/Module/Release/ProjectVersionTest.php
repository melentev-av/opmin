<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

use Internal\Path;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Release\ProjectVersion;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ProjectVersion::class)]
final class ProjectVersionTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-version-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    #[ExpectNoAssertions]
    public function aMatchingVersionRuns(): void
    {
        \file_put_contents($this->dir . '/.opmin-version', "1.2.3\n");

        ProjectVersion::check('1.2.3', null, Path::create($this->dir));
        ProjectVersion::check('1.4.0', '^1.2', Path::create($this->dir));
    }

    #[ExpectNoAssertions]
    public function aBuildFromSourcesIsNeverChecked(): void
    {
        ProjectVersion::check('experimental', '9.9.9', Path::create($this->dir));
    }

    public function theVersionFileNamesTheVersionToInstall(): never
    {
        \file_put_contents($this->dir . '/.opmin-version', "1.2.4\n");
        Expect::exception(ConfigException::class)
            ->withMessageContaining('This project expects opmin 1.2.4 (' . $this->dir . '/.opmin-version), but opmin 1.2.3 is running')
            ->withMessageContaining('opmin self-update --to=1.2.4');

        ProjectVersion::check('1.2.3', null, Path::create($this->dir));
    }

    public function requiresOfTheConfigWinsOverTheFile(): never
    {
        \file_put_contents($this->dir . '/.opmin-version', "1.2.3\n");
        Expect::exception(ConfigException::class)
            ->withMessageContaining('expects opmin ^2.0 (requires in opmin.yaml)')
            ->withMessageContaining('--to=<a version matching ^2.0>');

        ProjectVersion::check('1.2.3', '^2.0', Path::create($this->dir));
    }

    public function anUnreadableConstraintIsAConfigError(): never
    {
        Expect::exception(ConfigException::class)->withMessageContaining('Cannot read `newest` in the opmin version constraint `newest` (requires in opmin.yaml)');

        ProjectVersion::check('1.2.3', 'newest', Path::create($this->dir));
    }
}
