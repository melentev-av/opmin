<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Module\Package\Checkout;
use Opmin\Module\Package\PackageRun;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin optimize <git-url> --ref=<tag> --no-docker` on a package in a local bare repository. Docker mode is
 * exercised by the playground and the release images (the image of this opmin version does not exist yet).
 */
#[Test]
#[Covers(PackageRun::class)]
#[Covers(Checkout::class)]
final class OptimizePackageTest
{
    private const CODE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Tiny;

        final class Text
        {
            public function size(string $s): int
            {
                return strlen($s) + strlen(trim($s));
            }
        }

        PHP;

    private string $dir = '';

    #[BeforeTest]
    public function createPackage(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-package-test-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src/src', 0777, true);
        \mkdir($this->dir . '/cwd');
        \mkdir($this->dir . '/workspaces');
        $this->writePackage('>=8.1');
    }

    #[AfterTest]
    public function removePackage(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function optimizesTheTagAndLeavesTheReportAndThePatchHere(): void
    {
        [$code, $out, $err] = $this->opmin('--yes');

        Assert::same($code, 0, $out . $err);
        Assert::string($out . $err)->contains('Docker is not used')->contains('Report: ' . $this->dir . '/cwd/runs/');
        $reports = \glob($this->dir . '/cwd/runs/*/report.json') ?: [];
        Assert::count($reports, 1);
        $patch = (string) \file_get_contents($this->dir . '/cwd/opmin-acme-tiny-v1.0.0.patch');
        Assert::string($patch)->contains('+        return \strlen($s) + \strlen(\trim($s));');
        Assert::same(\glob($this->dir . '/workspaces/*'), []);
        # The tag of the package is untouched: the branch lived in the removed clone.
        Assert::string($this->git('--git-dir=' . $this->dir . '/package.git', 'branch', '--list'))->notContains('opmin/');
    }

    public function foreignCodeDoesNotRunWithoutConsent(): void
    {
        [$code, , $err] = $this->opmin();

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('Pass --yes to run foreign code without Docker');
        Assert::false(\is_dir($this->dir . '/cwd/runs'));
        Assert::same(\glob($this->dir . '/workspaces/*'), []);
    }

    public function stopsWhenPhpBinaryIsNotAPhpThePackageSupports(): void
    {
        $this->writePackage('>=99.0', 'v2.0.0');

        [$code, , $err] = $this->opmin('--yes', '--ref=v2.0.0');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)
            ->contains('The package requires PHP >=99.0, php.binary is PHP ' . TestPhp::binary()->version);
    }

    public function anUnknownRefIsAGitError(): void
    {
        [$code, , $err] = $this->opmin('--yes', '--ref=v9.9.9');

        Assert::same($code, 2);
        Assert::string($err)->contains('git checkout failed');
        Assert::same(\glob($this->dir . '/workspaces/*'), []);
    }

    private function writePackage(string $php, string $tag = 'v1.0.0'): void
    {
        $src = $this->dir . '/src';
        \file_put_contents($src . '/composer.json', \json_encode([
            'name' => 'acme/tiny',
            'require' => ['php' => $php],
            'autoload' => ['psr-4' => ['Acme\\Tiny\\' => 'src/']],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        \file_put_contents($src . '/src/Text.php', self::CODE);
        \file_put_contents($src . '/.gitignore', "/vendor/\n");
        \is_dir($src . '/.git') or $this->git('-C', $src, 'init', '-q');
        $this->git('-C', $src, 'add', '-A');
        $this->git('-C', $src, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', $tag, '--allow-empty');
        $this->git('-C', $src, 'tag', $tag);
        \is_dir($this->dir . '/package.git')
            ? $this->git('-C', $src, 'push', '-q', $this->dir . '/package.git', '--tags')
            : $this->git('clone', '-q', '--bare', $src, $this->dir . '/package.git');
    }

    /**
     * @return array{int, string, string}
     */
    private function opmin(string ...$args): array
    {
        $process = \proc_open(
            [
                \PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', 'optimize',
                'file://' . $this->dir . '/package.git', '--no-docker', '--no-interaction',
                '--set=package.workspace=' . $this->dir . '/workspaces',
                '--set=commands.phpstan=null',
                ...(\array_filter($args, static fn(string $a): bool => \str_starts_with($a, '--ref=')) === [] ? ['--ref=v1.0.0'] : []),
                ...$args,
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir . '/cwd',
            \array_merge(\getenv(), [
                'OPMIN_PHP_BINARY' => TestPhp::path(),
                'GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t',
                'COMPOSER_NO_INTERACTION' => '1',
            ]),
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out, $err];
    }

    private function git(string ...$args): string
    {
        $out = [];
        \exec('git ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        Assert::same($code, 0, \implode("\n", $out));

        return \implode("\n", $out);
    }
}
