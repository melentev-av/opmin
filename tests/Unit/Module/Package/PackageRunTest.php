<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Package;

use Internal\Path;
use Opmin\Module\Config\Schema;
use Opmin\Module\Package\Checkout;
use Opmin\Module\Package\PackageRun;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PackageRun::class)]
#[Covers(Checkout::class)]
final class PackageRunTest
{
    public function theTestsOfAPackageRunWithoutNetworkAndOnAReadOnlySystem(): void
    {
        $config = new Schema\Package();
        $config->cpus = 2;

        $tests = PackageRun::dockerRun(Path::create('/home/u/ws'), 'img:1', false, $config, '501:20');
        $install = PackageRun::dockerRun(Path::create('/home/u/ws'), 'img:1', true, new Schema\Package(), null, Path::create('/home/u/cache'));

        $line = \implode(' ', $tests);
        Assert::string($line)
            ->startsWith('docker run --rm --init --read-only --tmpfs /tmp:rw,exec,size=2g --memory 4g')
            ->contains('-v /home/u/ws:/workspace -w /workspace/package')
            ->contains('--network none')
            ->contains('--cpus 2')
            ->contains('--user 501:20')
            ->contains('--security-opt no-new-privileges');
        Assert::same(\end($tests), 'img:1');
        Assert::false(\in_array('--network', $install, true));
        Assert::false(\in_array('--cpus', $install, true));
        Assert::false(\in_array('--user', $install, true));
        Assert::string(\implode(' ', $install))->contains('-v /home/u/cache:/composer-cache -e COMPOSER_CACHE_DIR=/composer-cache');
        Assert::false(\str_contains($line, 'composer-cache'));
    }

    public function aFailedInstallShowsTheProblemBeforeTheLongHints(): void
    {
        $output = "Your requirements could not be resolved to an installable set of packages.\n\n"
            . "  Problem 1\n    - symfony/intl v7.3.0 requires ext-intl * -> it is missing from your system.\n"
            . \str_repeat("    hint line\n", 30);

        $problem = PackageRun::composerProblem($output);

        Assert::string($problem)
            ->startsWith('Problem 1')
            ->contains('requires ext-intl');
        Assert::same(\count(\explode("\n", $problem)), 15);
        Assert::same(PackageRun::composerProblem("a\nb\nc"), "a\nb\nc");
        $download = "  - Downloading a/b (1.0)\n\n  In CurlDownloader.php line 686:\n\n    The file could not be downloaded (HTTP/2 429)\n\n"
            . "  install [--prefer-source] [--dry-run]\n  [--] [<packages>...]";
        Assert::same(PackageRun::composerProblem($download), "In CurlDownloader.php line 686:\n    The file could not be downloaded (HTTP/2 429)");
    }

    public function removingTheWorkspaceNeverFollowsLinksOutOfIt(): void
    {
        $dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-tree-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/outside', 0777, true);
        \file_put_contents($dir . '/outside/keep.txt', 'mine');
        \mkdir($dir . '/workspace/package/vendor', 0777, true);
        \file_put_contents($dir . '/workspace/package/vendor/a.php', '<?php');
        \symlink($dir . '/outside', $dir . '/workspace/package/vendor/linked');
        \symlink($dir . '/outside/keep.txt', $dir . '/workspace/package/file-link');
        \chmod($dir . '/workspace/package/vendor', 0500);

        Checkout::removeTree($dir . '/workspace');

        Assert::false(\file_exists($dir . '/workspace'));
        Assert::same(\file_get_contents($dir . '/outside/keep.txt'), 'mine');
        \exec('rm -rf ' . \escapeshellarg($dir));
    }
}
