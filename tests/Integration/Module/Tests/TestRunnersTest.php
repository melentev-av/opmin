<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Tests;

use Internal\Path;
use Opmin\Module\Config\Schema\TestRunner;
use Opmin\Module\Config\Schema\Tests;
use Opmin\Module\Lint\PhpStanResult;
use Opmin\Module\Lint\PhpStanRunner;
use Opmin\Module\Lint\SyntaxChecker;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\Project;
use Opmin\Module\Tests\CommandAdapter;
use Opmin\Module\Tests\PestAdapter;
use Opmin\Module\Tests\PhpUnitAdapter;
use Opmin\Module\Tests\TestoAdapter;
use Opmin\Module\Tests\TestRunnerFactory;
use Opmin\Module\Config\Schema\Verification;
use Opmin\Module\Verification\CounterexampleRenderer;
use Opmin\Module\Verification\DiffTask;
use Opmin\Module\Verification\DiffTester;
use Opmin\Module\Verification\Target\TargetLocator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Adapters of the project's test runners and the static check, on temporary projects. Testo and
 * PHPStan are the ones in opmin's own vendor/; PHPUnit is a stand-in script that writes a JUnit
 * report (PHPUnit is not a dependency of opmin). They run under the PHP running the tests: these
 * tools need a newer PHP than the oldest php.binary of the matrix.
 */
#[Test]
#[Covers(TestoAdapter::class)]
#[Covers(PhpUnitAdapter::class)]
#[Covers(CommandAdapter::class)]
#[Covers(TestRunnerFactory::class)]
#[Covers(SyntaxChecker::class)]
#[Covers(PhpStanRunner::class)]
#[Covers(CounterexampleRenderer::class)]
final class TestRunnersTest
{
    private const VENDOR = __DIR__ . '/../../../../vendor';

    private string $dir;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-runners-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/tests', 0777, true);
        \mkdir($this->dir . '/src', 0777, true);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function testoReportsFailuresAndRunsASelection(): void
    {
        $this->testoProject();
        $adapter = new TestoAdapter($this->project(), self::php(), $this->work(), self::VENDOR . '/bin/testo');

        $all = $adapter->runAll();
        $selected = $adapter->runFiltered(['App\Tests\FTest::doubles']);
        $nothing = $adapter->runFiltered([]);

        Assert::same([$all->success, $all->tests, $all->failed], [false, 2, ['App\Tests\FTest::fails']]);
        Assert::same([$selected->success, $selected->tests], [true, 1]);
        Assert::same([$nothing->success, $nothing->tests], [true, 0]);
    }

    public function phpunitGetsTheJunitPathAndTheFilter(): void
    {
        \mkdir($this->dir . '/vendor/bin', 0777, true);
        # Stand-in for PHPUnit: one failing test, none when filtered; writes its arguments down.
        \file_put_contents($this->dir . '/vendor/bin/phpunit', <<<'PHP'
            #!/usr/bin/env php
            <?php
            $args = array_slice($argv, 1);
            file_put_contents(__DIR__ . '/args.json', json_encode($args));
            $junit = $args[array_search('--log-junit', $args, true) + 1];
            $filtered = in_array('--filter', $args, true);
            $case = $filtered ? '<testcase name="testOk" class="T"/>' : '<testcase name="testOk" class="T"/><testcase name="testBad" class="T"><failure>no</failure></testcase>';
            file_put_contents($junit, "<testsuites><testsuite>{$case}</testsuite></testsuites>");
            exit($filtered ? 0 : 1);
            PHP);
        $adapter = new PhpUnitAdapter($this->project(), self::php(), $this->work());

        $all = $adapter->runAll();
        $filtered = $adapter->runFiltered(['T::testOk']);
        /** @var list<string> $args */
        $args = \json_decode((string) \file_get_contents($this->dir . '/vendor/bin/args.json'), true);

        Assert::same([$all->success, $all->failed], [false, ['T::testBad']]);
        Assert::same([$filtered->success, $filtered->tests], [true, 1]);
        Assert::same(\array_slice($args, -2), ['--filter', '/^(?:T\:\:testOk(?: with data set .*)?)$/']);
        Assert::true(PhpUnitAdapter::detect($this->project()));
    }

    public function commandRunnerUsesTheExitCode(): void
    {
        $ok = (new CommandAdapter($this->project(), 'exit 0'))->runAll();
        $failed = (new CommandAdapter($this->project(), 'echo broken; exit 3'))->runAll();

        Assert::same([$ok->success, $failed->success], [true, false]);
        Assert::string($failed->output)->contains('broken');
        Assert::null((new CommandAdapter($this->project(), 'true'))->collectCoverageMap());
    }

    public function factoryDetectsTheRunner(): void
    {
        $config = new Tests();
        $none = TestRunnerFactory::create($this->project(), $config, self::php(), $this->work());
        $this->testoProject();
        $testo = TestRunnerFactory::create($this->project(), $config, self::php(), $this->work());
        \file_put_contents($this->dir . '/composer.json', '{"require-dev": {"pestphp/pest": "^3.0", "phpunit/phpunit": "^11"}}');
        $pest = TestRunnerFactory::create($this->project(), $config, self::php(), $this->work());
        $config->runner = TestRunner::Command;
        $config->command = 'make test';
        $command = TestRunnerFactory::create($this->project(), $config, self::php(), $this->work());

        Assert::null($none);
        Assert::instanceOf($testo, TestoAdapter::class);
        Assert::instanceOf($pest, PestAdapter::class);
        Assert::instanceOf($command, CommandAdapter::class);
    }

    public function syntaxIsCheckedByPhpBinary(): void
    {
        $checker = new SyntaxChecker(self::php(), $this->work());

        Assert::null($checker->check("<?php\nfunction f() { return 1; }\n"));
        Assert::string((string) $checker->check("<?php\nfunction f( {\n"))->contains('syntax error');
    }

    public function phpstanFindsOnlyNewErrors(): void
    {
        $file = $this->dir . '/src/F.php';
        $runner = new PhpStanRunner($this->project(), self::php(), self::VENDOR . '/bin/phpstan analyse --no-progress --error-format=json', '8.1', $this->work());
        \file_put_contents($file, "<?php\nfunction f(int \$a): int { return \$a + \$b; }\n");
        $before = $runner->analyse([Path::create($file)]);
        \file_put_contents($file, "<?php\n\nfunction f(int \$a): int { return \$a + \$b + \$c; }\n");
        $after = $runner->analyse([Path::create($file)]);

        Assert::true($runner->available());
        Assert::true($before->ran, $before->output);
        Assert::same(\count($before->errors), 1);
        Assert::same(\array_column(PhpStanResult::newErrors($before, $after), 'message'), ['Undefined variable: $c']);
    }

    public function counterexampleBecomesATestThatCatchesTheChange(): void
    {
        $this->testoProject();
        $file = $this->dir . '/src/f.php';
        $original = "<?php\nnamespace App;\nfunction f(int \$a) { if (\$a === 42) { throw new \\DomainException('forty-two'); } return \$a * 2; }\n";
        $changed = "<?php\nnamespace App;\nfunction f(int \$a) { return \$a * 2; }\n";
        \file_put_contents($file, $original);
        \unlink($this->dir . '/tests/FTest.php');
        $config = new Verification();
        $config->fuzzTimeMs = 200;
        $verdict = (new DiffTester(self::php(), $config, $this->work()))->verify(new DiffTask('App\f', Path::create($file), 'src/f.php', $original, $changed));
        $counterexample = $verdict->counterexample;
        Assert::notNull($counterexample);
        $target = (new TargetLocator())->locate($original, 'App\f', 'src/f.php');
        $adapter = new TestoAdapter($this->project(), self::php(), $this->work(), self::VENDOR . '/bin/testo');
        $test = (new CounterexampleRenderer())->render($target, $counterexample, $adapter, 'FCounterexampleTest');
        \file_put_contents($this->dir . '/tests/FCounterexampleTest.php', \str_replace('<?php', "<?php\nrequire_once __DIR__ . '/../src/f.php';", $test));

        $onOriginal = $adapter->runAll();
        \file_put_contents($file, $changed);
        $onChange = $adapter->runAll();

        Assert::string($test)->contains("Expect::exception(\\DomainException::class)->withMessage('forty-two')");
        Assert::same([$onOriginal->success, $onOriginal->tests], [true, 1], $onOriginal->output);
        Assert::false($onChange->success);
    }

    private static function php(): PhpBinary
    {
        return (new PhpBinaryProbe())->probe(\PHP_BINARY);
    }

    private function testoProject(): void
    {
        \file_put_contents($this->dir . '/testo.php', <<<'PHP'
            <?php
            use Testo\Application\Config\ApplicationConfig;
            use Testo\Application\Config\SuiteConfig;
            return new ApplicationConfig(src: ['src'], suites: [new SuiteConfig(name: 'Unit', location: ['tests'])]);
            PHP);
        \file_put_contents($this->dir . '/src/f.php', "<?php\nnamespace App;\nfunction f(int \$a) { return \$a * 2; }\n");
        \file_put_contents($this->dir . '/tests/FTest.php', <<<'PHP'
            <?php
            namespace App\Tests;
            require_once __DIR__ . '/../src/f.php';
            use Testo\Assert;
            use Testo\Test;
            #[Test]
            final class FTest {
                public function doubles(): void { Assert::same(\App\f(2), 4); }
                public function fails(): void { Assert::same(\App\f(2), 5); }
            }
            PHP);
    }

    private function project(): Project
    {
        return new Project(Path::create($this->dir), \is_file($this->dir . '/composer.json'), null);
    }

    private function work(): Path
    {
        return Path::create($this->dir)->join('.opmin-work');
    }
}
