<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Count;
use Opmin\Command\Diff;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin count` and `opmin diff` as processes, with php.binary from {@see TestPhp}.
 */
#[Test]
#[Covers(Count::class)]
#[Covers(Diff::class)]
final class CountTest
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Count';

    private string $dir;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-count-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}}');
        \file_put_contents($this->dir . '/opmin.yaml', '');
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function countMatchesHandCheckedDump(): void
    {
        /** @var array<string, array<string, array<string, int>>> $expected */
        $expected = require self::FIXTURES . '/expected.php';
        $files = $expected[TestPhp::minor()] ?? throw new \LogicException('No expectations for PHP ' . TestPhp::minor());
        foreach (\array_keys($files) as $file) {
            \copy(self::FIXTURES . "/{$file}", "{$this->dir}/src/{$file}");
        }

        [$code, $json] = $this->opmin('count', '--format=json');

        Assert::same($code, 0);
        $report = self::decode($json);
        $actual = [];
        foreach ($report['functions'] as $key => $function) {
            $actual[$function['file']][\str_replace($function['file'] . '::', '', $key)] = $function['ops_opt'];
        }
        foreach ($files as $file => $counts) {
            \ksort($counts);
            \ksort($actual["src/{$file}"]);
            Assert::same($actual["src/{$file}"], $counts);
        }
        Assert::same($report['php'], TestPhp::binary()->version);
        Assert::same($report['php_target'], '8.1');
        Assert::same($report['totals']['files'], \count($files));
    }

    public function secondRunTakesCountsFromCacheWithoutCompiling(): void
    {
        \copy(self::FIXTURES . '/Basic.php', "{$this->dir}/src/Basic.php");
        \file_put_contents("{$this->dir}/src/Other.php", "<?php\nfunction other(\$a) { return \$a + 1; }\n");
        # php.binary that logs its compile runs, then runs the real PHP.
        $log = "{$this->dir}/php.log";
        \file_put_contents("{$this->dir}/php", \sprintf(
            "#!/bin/sh\ncase \"\$*\" in *opt_debug_level*) echo compile >> %s ;; esac\nexec %s \"\$@\"\n",
            \escapeshellarg($log),
            \escapeshellarg(TestPhp::path()),
        ));
        \chmod("{$this->dir}/php", 0755);
        $env = ['OPMIN_PHP_BINARY' => "{$this->dir}/php"];

        [, $first] = $this->opminWithEnv($env, 'count', '--format=json');
        $compiledFirst = \count(\file($log) ?: []);
        [, $second] = $this->opminWithEnv($env, 'count', '--format=json');
        $compiledSecond = \count(\file($log) ?: []);
        \file_put_contents("{$this->dir}/src/Other.php", "<?php\nfunction other(\$a) { return \$a + 2; }\n");
        $this->opminWithEnv($env, 'count');
        $compiledThird = \count(\file($log) ?: []);

        Assert::same($compiledFirst, 1);
        Assert::same($compiledSecond, 1);
        Assert::same($second, $first);
        Assert::same($compiledThird, 2);
    }

    public function refusesToRunWithoutConfig(): void
    {
        \unlink($this->dir . '/opmin.yaml');
        \file_put_contents("{$this->dir}/src/A.php", "<?php\nfunction a() { return 1; }\n");

        [$code, , $err] = $this->opmin('count');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('No opmin.yaml in')->contains('opmin init');
        Assert::false(\file_exists("{$this->dir}/.opmin-cache"));
    }

    public function cacheOfPackageInMonorepoIsNextToTheConfig(): void
    {
        \mkdir("{$this->dir}/packages/http/src", 0777, true);
        \file_put_contents("{$this->dir}/packages/http/composer.json", '{}');
        \file_put_contents("{$this->dir}/packages/http/src/A.php", "<?php\nfunction a() { return 1; }\n");

        [$code] = $this->opmin('count', 'packages/http/src');

        Assert::same($code, 0);
        Assert::true(\is_dir("{$this->dir}/.opmin-cache/count"));
        Assert::false(\file_exists("{$this->dir}/packages/http/.opmin-cache"));
    }

    public function cacheIsNextToExplicitConfig(): void
    {
        \mkdir("{$this->dir}/conf");
        \file_put_contents("{$this->dir}/conf/opmin.yaml", "cache:\n  dir: .cache\n");
        \file_put_contents("{$this->dir}/src/A.php", "<?php\nfunction a() { return 1; }\n");

        [$code] = $this->opmin('count', '--config=conf/opmin.yaml', 'src');

        Assert::same($code, 0);
        Assert::true(\is_dir("{$this->dir}/conf/.cache/count"));
        Assert::false(\file_exists("{$this->dir}/.cache"));
    }

    public function memoryDriverWritesNoEntries(): void
    {
        \file_put_contents("{$this->dir}/src/A.php", "<?php\nfunction a() { return call_user_func('b'); }\n");

        [$code, $json] = $this->opmin('count', '--format=json', '--set=cache.driver=memory');

        Assert::same($code, 0);
        Assert::array(self::decode($json)['functions'])->hasKeys('a');
        Assert::false(\file_exists("{$this->dir}/.opmin-cache/count"));
        Assert::false(\file_exists("{$this->dir}/.opmin-cache/refs"));
    }

    public function reportsFilesThatCannotBeCompiledAndCountsTheRest(): void
    {
        \file_put_contents("{$this->dir}/src/Good.php", "<?php\nfunction good() { return 1; }\n");
        \file_put_contents("{$this->dir}/src/Bad.php", "<?php\nfunction bad( {\n");

        [$code, $json, $err] = $this->opmin('count', '--format=json');

        Assert::same($code, 1);
        $report = self::decode($json);
        Assert::array($report['functions'])->hasKeys('good');
        Assert::string($report['errors']['src/Bad.php'])->contains('syntax error');
        Assert::string($err)->contains('1 file(s) could not be counted');
    }

    public function filtersFunctionsAndShowsTable(): void
    {
        \copy(self::FIXTURES . '/Basic.php', "{$this->dir}/src/Basic.php");

        [$code, $out] = $this->opmin('count', 'src', '--filter=Fixture\Count\Service::map*');

        Assert::same($code, 0);
        Assert::string($out)->contains('Fixture\Count\Service::map::{closure:2}');
        Assert::string($out)->contains('src/Basic.php:62');
        Assert::string($out)->notContains('Fixture\Count\top');
        Assert::string($out)->contains('in 3 functions of 1 files');
    }

    public function reportsFlagsOfDynamicConstructs(): void
    {
        \file_put_contents("{$this->dir}/src/F.php", <<<'PHP'
            <?php
            namespace App;
            function vars($a) { return compact('a'); }
            function counter() { static $n = 0; return ++$n; }
            function plain($a) { return $a + 1; }
            function callback($a) { return $a; }
            PHP);
        \file_put_contents("{$this->dir}/routes.php", "<?php\n\$f = 'App\\\\callback';\n");

        [$code, $json] = $this->opmin('count', '--format=json');

        Assert::same($code, 0);
        /** @var array{functions: array<string, array{flags: list<string>}>} $report */
        $report = \json_decode($json, true);
        Assert::same($report['functions']['App\\vars']['flags'], ['compact']);
        Assert::same($report['functions']['App\\counter']['flags'], ['static_var']);
        Assert::same($report['functions']['App\\plain']['flags'], []);
        Assert::same($report['functions']['App\\callback']['flags'], ['called_dynamically']);
    }

    public function failsOnMissingPhpBinary(): void
    {
        [$code, , $err] = $this->opminWithEnv(['OPMIN_PHP_BINARY' => '/nonexistent/php'], 'count');

        Assert::same($code, 2);
        Assert::string($err)->contains('php.binary `/nonexistent/php`');
    }

    public function failsOnMissingPath(): void
    {
        [$code, , $err] = $this->opmin('count', 'nope');

        Assert::same($code, 2);
        Assert::string($err)->contains('does not exist');
    }

    public function diffShowsDeltasAndRefusesOtherPhp(): void
    {
        \file_put_contents("{$this->dir}/src/F.php", "<?php\nfunction f(\$a) { if (\$a > 1) { return \$a; } return 0; }\nfunction g() { return 1; }\n");
        [, $before] = $this->opmin('count', '--format=json');
        \file_put_contents("{$this->dir}/src/F.php", "<?php\nfunction f(\$a) { return \$a; }\nfunction g() { return \\strlen('a') + \\strlen(\$GLOBALS['x']); }\n");
        [, $after] = $this->opmin('count', '--format=json');
        \file_put_contents("{$this->dir}/before.json", $before);
        \file_put_contents("{$this->dir}/after.json", $after);
        \file_put_contents("{$this->dir}/other.json", \str_replace('"php": "', '"php": "7.0-', $after));

        [$code, $out] = $this->opmin('diff', 'before.json', 'after.json');
        [$otherCode, , $otherErr] = $this->opmin('diff', 'before.json', 'other.json');

        Assert::same($code, 0);
        Assert::string($out)->contains('Fewer opcodes');
        Assert::string($out)->contains('More opcodes');
        Assert::string($out)->contains('1 fewer, 1 more');
        Assert::same($otherCode, 2);
        Assert::string($otherErr)->contains('different PHP versions');
    }

    /**
     * @return array{php: string, php_target: string, totals: array{files: int}, functions: array<string, array{file: string, ops_opt: int}>, errors: array<string, string>}
     */
    private static function decode(string $json): array
    {
        /** @var array{php: string, php_target: string, totals: array{files: int}, functions: array<string, array{file: string, ops_opt: int}>, errors: array<string, string>} */
        return \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function opmin(string ...$args): array
    {
        return $this->opminWithEnv([], ...$args);
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function opminWithEnv(array $env, string ...$args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            \array_merge(\getenv(), ['OPMIN_PHP_BINARY' => TestPhp::path()], $env),
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out, $err];
    }
}
