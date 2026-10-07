<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\Verify;
use Opmin\Module\Verification\Verifier;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin verify` as a process, php.binary from {@see TestPhp}.
 */
#[Test]
#[Covers(Verify::class)]
#[Covers(Verifier::class)]
final class VerifyTest
{
    private const ORIGINAL = <<<'PHP'
        <?php
        namespace App;
        function f(array $a) { return array_key_exists('x', $a) ? 'yes' : 'no'; }
        function g(int $a, int $b) { $s = $a + $b; return $s; }
        PHP;

    private string $dir;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-verify-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}}');
        \file_put_contents($this->dir . '/src/A.php', self::ORIGINAL);
        \file_put_contents($this->dir . '/opmin.yaml', "verification:\n  fuzz_time_ms: 300\n");
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function acceptsAnEquivalentChange(): void
    {
        $this->candidate(\str_replace('$s = $a + $b; return $s;', 'return $a + $b;', self::ORIGINAL));

        [$code, $json] = $this->opmin('verify', 'src/A.php', 'candidate.php', '--format=json');

        Assert::same($code, 0);
        $report = self::decode($json);
        Assert::same($report['accepted'], true);
        Assert::same(\array_keys($report['functions']), ['App\g']);
        Assert::same($report['functions']['App\g']['status'], 'diff-tested');
    }

    public function rejectsAChangeAndWritesTheCounterexample(): void
    {
        $this->candidate(\str_replace("array_key_exists('x', \$a)", "isset(\$a['x'])", self::ORIGINAL));

        [$code, $out] = $this->opmin('verify', 'src/A.php', 'candidate.php');

        Assert::same($code, 1);
        Assert::string($out)->contains('The candidate is rejected');
        $files = \glob($this->dir . '/runs/*/counterexamples/*') ?: [];
        Assert::same(\array_map('basename', $files), ['OpminAppFCounterexampleTest.json', 'OpminAppFCounterexampleTest.php']);
        Assert::string((string) \file_get_contents($files[1]))->contains("\$arg0 = ['x' => null];");
    }

    public function rejectsASyntaxError(): void
    {
        $this->candidate("<?php\nfunction f( {\n");

        [$code, $json] = $this->opmin('verify', 'src/A.php', 'candidate.php', '--format=json');

        Assert::same($code, 1);
        Assert::string((string) self::decode($json)['syntax_error'])->contains('syntax error');
    }

    public function projectTestsMustPassOnTheOriginalAndOnTheChange(): void
    {
        # The project's "test suite": a script that checks g().
        \file_put_contents($this->dir . '/check.php', "<?php\nrequire __DIR__ . '/src/A.php';\nexit(\\App\\g(2, 2) === 4 ? 0 : 1);\n");
        $tests = ['--with-tests', '--set=tests.runner=command', '--set=tests.command=' . \escapeshellarg(\PHP_BINARY) . ' check.php'];
        $this->candidate(\str_replace('$s = $a + $b; return $s;', 'return $a * $b;', self::ORIGINAL));

        [$sameResult, $json] = $this->opmin('verify', 'src/A.php', 'candidate.php', '--format=json', ...$tests);
        $this->candidate(\str_replace('$s = $a + $b; return $s;', 'return $a - $b;', self::ORIGINAL));
        [$broken, $brokenJson] = $this->opmin('verify', 'src/A.php', 'candidate.php', '--format=json', ...$tests);
        \file_put_contents($this->dir . '/check.php', "<?php\nexit(1);\n");
        [$red, $redJson] = $this->opmin('verify', 'src/A.php', 'candidate.php', '--format=json', ...$tests);

        # 2 * 2 === 2 + 2: the weak test passes, the differential test does not.
        Assert::same($sameResult, 1);
        Assert::same(self::decode($json)['tests']['success'] ?? null, true);
        Assert::same(self::decode($json)['functions']['App\g']['status'], 'rejected');
        Assert::same($broken, 1);
        Assert::same(self::decode($brokenJson)['tests']['success'] ?? null, false);
        Assert::same($red, 1);
        Assert::string(\implode("\n", self::decode($redJson)['notes']))->contains('fail on the original code');
        Assert::same(\file_get_contents($this->dir . '/src/A.php'), self::ORIGINAL);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        /** @var array<string, mixed> */
        return \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    private function candidate(string $code): void
    {
        \file_put_contents($this->dir . '/candidate.php', $code);
    }

    /**
     * @return array{int, string, string}
     */
    private function opmin(string ...$args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            \array_merge(\getenv(), ['OPMIN_PHP_BINARY' => TestPhp::path()]),
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        $code = \proc_close($process);

        return [$code, $out, $err];
    }
}
