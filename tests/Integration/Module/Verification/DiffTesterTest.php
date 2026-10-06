<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Verification;

use Internal\Path;
use Opmin\Module\Config\Schema\Verification;
use Opmin\Module\Verification\DiffTask;
use Opmin\Module\Verification\DiffTester;
use Opmin\Module\Verification\Verdict;
use Opmin\Module\Verification\VerdictStatus;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Mechanics of the differential tester under php.binary from {@see TestPhp}. The table of traps and
 * equivalent pairs is {@see VerifierTrapsTest}.
 */
#[Test]
#[Covers(DiffTester::class)]
final class DiffTesterTest
{
    private string $dir;

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-diff-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function counterexampleIsShrunkToTheMagicLiteral(): void
    {
        $verdict = $this->verify(
            '<?php namespace App; function f(string $s, int $n) { if ($s === "open sesame" && $n > 100) { return 1; } return 0; }',
            '<?php namespace App; function f(string $s, int $n) { return 0; }',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::same($verdict->counterexample?->input->args, [['type' => 'string', 'value' => 'open sesame'], ['type' => 'int', 'value' => 101]]);
    }

    public function equivalentRewriteIsAccepted(): void
    {
        $verdict = $this->verify(
            '<?php namespace App; function f(array $a) { $n = count($a); if ($n === 0) { return null; } return $a[$n - 1]; }',
            '<?php namespace App; function f(array $a) { if ($a === []) { return null; } return $a[\count($a) - 1]; }',
        );

        Assert::same($verdict->status, VerdictStatus::Equivalent, $verdict->reason);
        Assert::same($verdict->coverage, 100.0);
    }

    public function exitInTheChangedVersionIsAResult(): void
    {
        $verdict = $this->verify(
            '<?php function f($a) { return $a; }',
            '<?php function f($a) { if ($a === 3) { exit(1); } return $a; }',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::string($verdict->reason)->contains('status');
    }

    public function hangInTheChangedVersionIsAResult(): void
    {
        $verdict = $this->verify(
            '<?php function f(int $a) { return $a; }',
            '<?php function f(int $a) { while ($a === 7) {} return $a; }',
            callTimeoutMs: 300,
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::same($verdict->counterexample?->changed['status'], 'timeout');
    }

    public function nondeterministicFunctionIsUnverified(): void
    {
        $verdict = $this->verify(
            '<?php function f() { return \random_int(1, PHP_INT_MAX); }',
            '<?php function f() { return \random_int(1, PHP_INT_MAX); }',
        );

        Assert::same($verdict->status, VerdictStatus::Unverified);
        Assert::string($verdict->reason)->contains('nondeterministic');
    }

    public function fakedTimeMakesNamespacedCodeDeterministic(): void
    {
        $verdict = $this->verify(
            '<?php namespace App; function f() { return date("Y-m-d", time()) . random_int(1, 10); }',
            '<?php namespace App; function f() { return date("Y-m-d", time()) . random_int(1, 10); }',
        );

        Assert::same($verdict->status, VerdictStatus::Equivalent, $verdict->reason);
    }

    public function evalIsSkipped(): void
    {
        $verdict = $this->verify('<?php function f($c) { return eval($c); }', '<?php function f($c) { return eval($c); }');

        Assert::same($verdict->status, VerdictStatus::Skipped);
    }

    public function lowCoverageIsUnverified(): void
    {
        $verdict = $this->verify(
            '<?php function f(int $a) { if (md5((string) $a) === "00000000000000000000000000000000") { return 1; } return 0; }',
            '<?php function f(int $a) { if (md5((string) $a) === "00000000000000000000000000000000") { return 1; } return 0; }',
        );

        Assert::same($verdict->status, VerdictStatus::Unverified);
        Assert::string($verdict->reason)->contains('min_branch_coverage');
    }

    public function staticVariableChainIsCompared(): void
    {
        $verdict = $this->verify(
            '<?php function f() { static $n = 0; return ++$n; }',
            '<?php function f() { static $n = 0; $n++; return 1; }',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::string($verdict->reason)->contains('calls[1]');
    }

    public function methodsGetThisAndCompareItsState(): void
    {
        $verdict = $this->verify(
            '<?php namespace App; final class Cart { private array $items = []; public function add(string $sku, int $qty): int { $this->items[$sku] = ($this->items[$sku] ?? 0) + $qty; return count($this->items); } }',
            '<?php namespace App; final class Cart { private array $items = []; public function add(string $sku, int $qty): int { $this->items[$sku] = $qty; return count($this->items); } }',
            key: 'App\Cart::add',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
    }

    public function closuresAreCalledThroughWrappers(): void
    {
        $verdict = $this->verify(
            '<?php namespace App; function make(int $k) { return function (int $x) use ($k) { return $x * $k; }; }',
            '<?php namespace App; function make(int $k) { return function (int $x) use ($k) { return $x + $k; }; }',
            key: 'App\make::{closure:1}',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
    }

    public function mockCallOrderIsCompared(): void
    {
        $verdict = $this->verify(
            '<?php namespace App; interface Log { public function write(string $m): void; } function f(Log $log) { $log->write("a"); $log->write("b"); return 1; }',
            '<?php namespace App; interface Log { public function write(string $m): void; } function f(Log $log) { $log->write("b"); $log->write("a"); return 1; }',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::string($verdict->reason)->contains('mocks');
    }

    /**
     * @param non-empty-string $key
     * @param positive-int $callTimeoutMs
     */
    private function verify(string $original, string $changed, string $key = 'App\f', int $callTimeoutMs = 2000): Verdict
    {
        \str_contains($original, 'namespace App;') or $key = \str_replace('App\\', '', $key);
        $file = Path::create($this->dir)->join('Code.php');
        \file_put_contents((string) $file, $original);
        $config = new Verification();
        $config->fuzzTimeMs = 500;
        $config->callTimeoutMs = $callTimeoutMs;
        $tester = new DiffTester(TestPhp::binary(), $config, Path::create($this->dir));

        /** @var non-empty-string $key */
        return $tester->verify(new DiffTask($key, $file, 'Code.php', $original, $changed));
    }
}
