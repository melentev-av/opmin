<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

use Internal\Path;
use Opmin\Module\Release\Delegation;
use Opmin\Module\Release\Installation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Delegation::class)]
final class DelegationTest
{
    private string $dir = '';
    private Installation $global;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-delegation-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/project/src/Deep', 0777, true);
        \mkdir($this->dir . '/global');
        \file_put_contents($this->dir . '/project/composer.json', '{}');
        $this->global = new Installation(Installation::BINARY, $this->executable('global/opmin'));
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function handsOverToTheComposerPackageOfTheProject(): void
    {
        \mkdir($this->dir . '/project/vendor/bin', 0777, true);
        $local = $this->executable('project/vendor/bin/opmin');
        $this->executable('project/opmin');

        Assert::same((string) Delegation::target($this->global, $this->path('project/src/Deep'), []), (string) $local);
    }

    public function handsOverToTheBinaryDloadPutInTheRoot(): void
    {
        $local = $this->executable('project/opmin');

        Assert::same((string) Delegation::target($this->global, $this->path('project'), []), (string) $local);
    }

    public function runsItselfWithoutALocalOpmin(): void
    {
        \file_put_contents($this->dir . '/project/opmin', 'not executable');

        Assert::null(Delegation::target($this->global, $this->path('project'), []));
        Assert::null(Delegation::target($this->global, $this->path('global'), []));
    }

    public function neverHandsOverToItself(): void
    {
        $this->executable('project/opmin');
        $self = new Installation(Installation::PHAR, $this->path('project/src/../opmin'));

        Assert::null(Delegation::target($self, $this->path('project'), []));
    }

    public function theEnvironmentAndSourcesKeepTheRunningOne(): void
    {
        $this->executable('project/opmin');

        Assert::null(Delegation::target($this->global, $this->path('project'), ['OPMIN_NO_DELEGATE' => '1']));
        Assert::null(Delegation::target($this->global, $this->path('project'), ['OPMIN_DELEGATED' => '1']));
        Assert::null(Delegation::target(new Installation(Installation::SOURCES, null), $this->path('project'), []));
    }

    public function passesArgumentsAndTheExitCode(): void
    {
        $local = $this->executable('project/opmin', "#!/bin/sh\n[ \"\$OPMIN_DELEGATED\" = 1 ] && [ \"\$1\" = count ] && exit 7\nexit 1\n");

        Assert::same(Delegation::run($local, ['count'], ['PATH' => (string) \getenv('PATH')]), 7);
    }

    private function executable(string $relative, string $content = "#!/bin/sh\n"): Path
    {
        \file_put_contents($this->dir . '/' . $relative, $content);
        \chmod($this->dir . '/' . $relative, 0755);

        return $this->path($relative);
    }

    private function path(string $relative): Path
    {
        return Path::create($this->dir . '/' . $relative);
    }
}
