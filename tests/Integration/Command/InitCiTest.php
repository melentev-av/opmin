<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Init;
use Opmin\Info;
use Opmin\Module\Check\Ci\CiSetup;
use Opmin\Tests\Integration\TestPhp;
use Symfony\Component\Yaml\Yaml;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin init --ci=github|gitlab` as a process: the job for the PHP minor of php.binary (read from
 * opmin.yaml), never overwriting a file, never touching `.gitlab-ci.yml`.
 */
#[Test]
#[Covers(Init::class)]
#[Covers(CiSetup::class)]
final class InitCiTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-init-ci-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
        \file_put_contents($this->dir . '/opmin.yaml', "php:\n  binary: " . \json_encode(TestPhp::path()) . "\n  target: '8.1'\n");
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function githubWritesTheWorkflowForThePhpOfPhpBinary(): void
    {
        [$code, $out] = $this->init('--ci=github');

        Assert::same($code, 0);
        $file = $this->dir . '/.github/workflows/opcodes.yml';
        /** @var array{jobs: array{opmin: array{steps: list<array<string, mixed>>}}} $workflow */
        $workflow = Yaml::parseFile($file);
        Assert::same($workflow['jobs']['opmin']['steps'][1], [
            'uses' => 'melentev-av/opmin@v' . Info::version(),
            'with' => ['php' => TestPhp::minor()],
        ]);
        Assert::string($out)
            ->ignoringWhitespace(lineBreaks: true)
            ->contains('PHP ' . TestPhp::minor() . ': the minor of php.binary `' . TestPhp::path() . '` (PHP ' . TestPhp::binary()->version . ')')
            ->contains('docker run --rm -v "$PWD:/app" ghcr.io/melentev-av/opmin:' . Info::version() . '-php' . TestPhp::minor() . ' opmin baseline');
        Assert::false(\file_exists($this->dir . '/.gitlab'));
    }

    public function anExistingFileIsNeverOverwritten(): void
    {
        \mkdir($this->dir . '/.github/workflows', 0777, true);
        \file_put_contents($this->dir . '/.github/workflows/opcodes.yml', "# mine\n");

        [$code, $out] = $this->init('--ci=github');

        Assert::same($code, 2);
        Assert::string($out)->ignoringWhitespace(lineBreaks: true)->contains('.github/workflows/opcodes.yml already exists: opmin does not overwrite it.');
        Assert::same(\file_get_contents($this->dir . '/.github/workflows/opcodes.yml'), "# mine\n");
    }

    public function gitlabWritesAnIncludedFileAndLeavesGitlabCiAlone(): void
    {
        \file_put_contents($this->dir . '/.gitlab-ci.yml', "stages: [test]\n");

        [$code, $out] = $this->init('--ci=gitlab');

        Assert::same($code, 0);
        /** @var array{opcodes: array{image: string}} $job */
        $job = Yaml::parseFile($this->dir . '/.gitlab/opmin.yml');
        Assert::same($job['opcodes']['image'], 'ghcr.io/melentev-av/opmin:' . Info::version() . '-php' . TestPhp::minor());
        Assert::string($out)->contains("   include:\n     - local: .gitlab/opmin.yml\n");
        Assert::same(\file_get_contents($this->dir . '/.gitlab-ci.yml'), "stages: [test]\n");
    }

    public function anUnknownCiSystemIsAnInvalidUsage(): void
    {
        [$code, $out] = $this->init('--ci=jenkins');

        Assert::same($code, 2);
        Assert::string($out)->contains('--ci takes github | gitlab, not `jenkins`.');
        Assert::false(\file_exists($this->dir . '/.github'));
    }

    public function aPhpBinaryThatDoesNotStartWritesNothing(): void
    {
        [$code, $out] = $this->init('--ci=github', '--set=php.binary=' . $this->dir . '/no-php');

        Assert::same($code, 2);
        Assert::string($out)->ignoringWhitespace(lineBreaks: true)->contains('`init --ci` takes the PHP minor of the image from php.binary');
        Assert::false(\file_exists($this->dir . '/.github'));
    }

    /**
     * @return array{int, string} Exit code and the whole output.
     */
    private function init(string ...$args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/opmin', '--no-ansi', 'init', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out . $err];
    }
}
