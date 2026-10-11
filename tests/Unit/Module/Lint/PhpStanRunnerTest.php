<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Lint;

use Internal\Path;
use Opmin\Module\Lint\PhpStanRunner;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The generated PHPStan config: a stable name (PHPStan keys its container by config paths) and
 * an absolute `tmpDir` under the cache of opmin.
 */
#[Test]
#[Covers(PhpStanRunner::class)]
final class PhpStanRunnerTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-phpstan-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/work', 0777, true);
        \file_put_contents($this->dir . '/phpstan.neon', "parameters:\n    level: 6\n");
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function sameContentGivesTheSameConfig(): void
    {
        $first = $this->runner('8.3')->config();
        $second = $this->runner('8.3')->config();

        Assert::same((string) $second, (string) $first);
        Assert::string($first->name())->matchesRegex('/^phpstan-opmin-[0-9a-f]{12}\.neon$/');
        Assert::count(\glob($this->dir . '/work/*') ?: [], 1);
    }

    public function anotherPhpTargetGivesAnotherConfig(): void
    {
        $old = $this->runner('8.1')->config();
        $new = $this->runner('8.4')->config();

        Assert::false((string) $old === (string) $new);
        Assert::string((string) \file_get_contents((string) $old))->contains('phpVersion: 80100');
        Assert::string((string) \file_get_contents((string) $new))->contains('phpVersion: 80400');
    }

    public function fingerprintFollowsTheTargetAndTheProjectConfig(): void
    {
        $first = $this->runner('8.3')->fingerprint();
        $same = $this->runner('8.3')->fingerprint();
        $otherTarget = $this->runner('8.4')->fingerprint();
        \file_put_contents($this->dir . '/phpstan.neon', "parameters:\n    level: 7\n");
        $otherConfig = $this->runner('8.3')->fingerprint();

        Assert::same($same, $first);
        Assert::false($otherTarget === $first);
        Assert::false($otherConfig === $first);
    }

    public function tmpDirIsAnAbsolutePathAfterTheProjectConfig(): void
    {
        $default = (string) \file_get_contents((string) $this->runner('8.3')->config());
        $given = (string) \file_get_contents((string) $this->runner('8.3', Path::create('/var/cache/opmin/phpstan'))->config());

        Assert::same($default, \implode("\n", [
            'includes:',
            '    - "' . $this->dir . '/phpstan.neon"',
            'parameters:',
            '    phpVersion: 80300',
            '    tmpDir: "' . $this->dir . '/.opmin/cache/phpstan"',
            '',
        ]));
        Assert::string($given)->contains("\n    tmpDir: \"/var/cache/opmin/phpstan\"\n");
    }

    private function runner(string $phpTarget, ?Path $tmpDir = null): PhpStanRunner
    {
        return new PhpStanRunner(
            new Project(Path::create($this->dir), false, null),
            new PhpBinary('php', '8.3.0', 80300, [], []),
            'vendor/bin/phpstan analyse',
            $phpTarget,
            Path::create($this->dir . '/work'),
            tmpDir: $tmpDir,
        );
    }
}
