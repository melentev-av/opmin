<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

use Opmin\Module\Release\Platform;
use Opmin\Module\Release\ReleaseException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Platform::class)]
final class PlatformTest
{
    public static function machines(): iterable
    {
        yield 'linux intel' => ['Linux', 'x86_64', 'linux-x86_64'];
        yield 'linux amd64' => ['Linux', 'amd64', 'linux-x86_64'];
        yield 'linux arm' => ['Linux', 'aarch64', 'linux-aarch64'];
        yield 'linux arm64 name' => ['Linux', 'arm64', 'linux-aarch64'];
        yield 'mac intel' => ['Darwin', 'x86_64', 'macos-x86_64'];
        yield 'mac apple silicon' => ['Darwin', 'arm64', 'macos-arm64'];
    }

    #[DataProvider('machines')]
    public function mapsTheMachineToAReleaseTarget(string $os, string $machine, string $target): void
    {
        Assert::same(Platform::of($os, $machine), $target);
        Assert::true(\in_array($target, Platform::TARGETS, true));
    }

    public function namesTheArchiveByVersionAndTarget(): void
    {
        Assert::same(Platform::archive('1.2.3', 'macos-arm64'), 'opmin-1.2.3-macos-arm64.tar.gz');
    }

    public function windowsHasNoBinary(): never
    {
        Expect::exception(ReleaseException::class)->withMessageContaining('no opmin binary for Windows');

        Platform::of('Windows', 'AMD64');
    }

    public function anUnknownArchitectureHasNoBinary(): never
    {
        Expect::exception(ReleaseException::class)->withMessageContaining('no opmin binary for linux on riscv64');

        Platform::of('Linux', 'riscv64');
    }
}
