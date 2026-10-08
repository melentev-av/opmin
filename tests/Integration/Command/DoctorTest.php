<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Doctor as DoctorCommand;
use Opmin\Module\Doctor\Doctor;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin doctor` as a process on a small project, under the php.binary of the test run.
 */
#[Test]
#[Covers(DoctorCommand::class)]
#[Covers(Doctor::class)]
final class DoctorTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-doctor-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/composer.json', \json_encode(['require' => ['php' => '>=' . TestPhp::minor()]]));
        \file_put_contents($this->dir . '/src/Price.php', "<?php\nfunction price(int \$cents): float { return \$cents / 100; }\n");
        \file_put_contents($this->dir . '/opmin.yaml', "php:\n  binary: " . \json_encode(TestPhp::path()) . "\ncommands:\n  phpstan: null\n");
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function aHealthyProjectIsReady(): void
    {
        [$code, $out] = $this->doctor();

        Assert::same($code, 0);
        Assert::string($out)
            ->contains('✔ php.binary: PHP ' . TestPhp::binary()->version)
            ->contains('✔ opcache_compile_file: compiles and dumps opcodes')
            ->contains('✔ harness: the differential-testing worker starts under php.binary')
            ->contains('✔ PHP version: php.binary ' . TestPhp::binary()->version . ' matches php.target ' . TestPhp::minor())
            ->contains('✔ syntax: 1 file(s) parse')
            ->contains('! tests: no test runner found')
            ->contains('i PHPStan: commands.phpstan is null')
            ->contains('Ready');
    }

    public function aMissingPhpBinaryComesFirstAndSkipsWhatDependsOnIt(): void
    {
        [$code, $out] = $this->doctor('--set=php.binary=opmin-no-such-php');

        Assert::same($code, 1);
        Assert::string($out)
            ->startsWith(' ✘ php.binary: `opmin-no-such-php` cannot be started.')
            ->contains('Fix: Install PHP')
            ->contains('- harness: not checked')
            ->contains('- syntax: not checked')
            ->contains('1 problem(s) block opmin');
    }

    public function namesFilesWithSyntaxPhpBinaryCannotParse(): void
    {
        \file_put_contents($this->dir . '/src/Broken.php', "<?php\nfunction broken( { }\n");

        [$code, $out] = $this->doctor();

        Assert::same($code, 1);
        Assert::string($out)->contains('✘ syntax: 1 file(s) do not parse')->contains('src/Broken.php:2');
    }

    public function warnsWhenPhpTargetDiffersFromPhpBinary(): void
    {
        [$code, $out] = $this->doctor('--set=php.target=7.4');

        Assert::same($code, 0);
        Assert::string($out)->contains('! PHP version: php.binary is PHP ' . TestPhp::binary()->version . ', php.target is 7.4');
    }

    public function aCommandRunnerWithoutCommandIsAnError(): void
    {
        [$code, $out] = $this->doctor('--set=tests.runner=command');

        Assert::same($code, 1);
        Assert::string($out)->contains('✘ tests: tests.runner is `command`, but tests.command is not set.');
    }

    public function runsTheSuiteWithTests(): void
    {
        \file_put_contents($this->dir . '/check.php', "<?php\nrequire __DIR__ . '/src/Price.php';\nexit(price(150) === 1.5 ? 0 : 1);\n");
        $runner = ['--set=tests.runner=command', '--set=tests.command=' . TestPhp::path() . ' check.php'];

        [$code, $out] = $this->doctor(...$runner);
        [$withTests, $ran] = $this->doctor('--with-tests', ...$runner);

        Assert::same($code, 0);
        Assert::string($out)->contains('i tests: tests.command:');
        Assert::same($withTests, 0);
        Assert::string($ran)->contains('✔ tests: command: the suite passes');
    }

    /**
     * @return array{int, string}
     */
    private function doctor(string ...$args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/opmin', '--no-ansi', 'doctor', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out . $err];
    }
}
