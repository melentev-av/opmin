<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Verification;

use Internal\Path;
use Opmin\Module\Config\Schema\CoverageDriver;
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

    public function warningsThrownAsExceptionsShowWhatRecordingHides(): void
    {
        # Recorded, both versions print and warn; thrown, the changed one stops before printing.
        $verdict = $this->verify(
            '<?php function f() { echo "a"; trigger_error("w", E_USER_WARNING); return 1; }',
            '<?php function f() { trigger_error("w", E_USER_WARNING); echo "a"; return 1; }',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::same($verdict->counterexample?->original['calls'][0]['status'] ?? null, 'threw');
    }

    public function inputsTheOriginalHangsOnAreDiscarded(): void
    {
        $verdict = $this->verify(
            '<?php function f(int $a) { while ($a === 5) {} return $a; }',
            '<?php function f(int $a) { return $a; }',
            callTimeoutMs: 300,
        );

        Assert::same($verdict->status, VerdictStatus::Equivalent, $verdict->reason);
    }

    public function staticChainIsThreeCallsLong(): void
    {
        $verdict = $this->verify(
            '<?php function f() { static $n = 0; return ++$n; }',
            '<?php function f() { static $n = 0; return \min(++$n, 2); }',
        );

        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::string($verdict->reason)->contains('calls[2]');
    }

    public function coverageGuidedSearchCombinesInputsThatOpenedBranches(): void
    {
        $code = '<?php function f(int $a, int $b) { if ($a === 41) { if ($b === 43) { return "both"; } return "a"; } return "none"; }';

        $searched = $this->verify($code, $code, fuzzTimeMs: 3000);
        $planOnly = $this->verify($code, $code, fuzzTimeMs: 0);

        Assert::same($searched->coverage, 100.0);
        Assert::true($planOnly->coverage < 100.0, (string) $planOnly->coverage);
    }

    public function thresholdOfCoverageIsInclusive(): void
    {
        # `?? 2` is never evaluated: half of the probes can be hit.
        $code = '<?php function f() { return 1 ?? 2; }';

        Assert::same($this->verify($code, $code, minCoverage: 50)->status, VerdictStatus::Equivalent);
        Assert::same($this->verify($code, $code, minCoverage: 51)->status, VerdictStatus::Unverified);
    }

    public function otherChangedFilesAreSeenByTheChangedVersionOnly(): void
    {
        $helper = $this->dir . '/Helper.php';
        \file_put_contents($helper, "<?php\nfunction helper(int \$x) { return \$x * 2; }\n");
        $code = "<?php\nrequire_once __DIR__ . '/Helper.php';\nfunction f(int \$x) { return helper(\$x); }\n";
        $file = Path::create($this->dir)->join('Code.php');
        \file_put_contents((string) $file, $code);
        $config = new Verification();
        $config->fuzzTimeMs = 0;

        $verdict = (new DiffTester(TestPhp::binary(), $config, Path::create($this->dir)))->verify(new DiffTask(
            'f',
            $file,
            'Code.php',
            $code,
            $code,
            otherChanges: [$helper => ["<?php\nfunction helper(int \$x) { return \$x * 2; }\n", "<?php\nfunction helper(int \$x) { return \$x + 2; }\n"]],
        ));

        Assert::same($verdict->status, VerdictStatus::Mismatch);
    }

    public function workFilesAreRemovedAndTheTimeIsMeasured(): void
    {
        $verdict = $this->verify('<?php function f($a) { return $a; }', '<?php function f($a) { return $a; }');

        Assert::same(\glob($this->dir . '/verify-*') ?: [], []);
        Assert::true($verdict->seconds > 0.0 && $verdict->seconds < 60.0);
        Assert::true($verdict->inputs > 0);
    }

    public function phpdocClassesResolveThroughUseImports(): void
    {
        $code = static fn(string $call): string => "<?php namespace App;\nuse App\\Ports\\Clock as Timer;\n"
            . "interface Unused {}\n"
            . "/** @param Timer \$t */ function f(\$t) { return {$call}; }\n";
        $port = $this->dir . '/Port.php';
        \file_put_contents($port, "<?php namespace App\\Ports; interface Clock { public function now(): int; }\n");
        $file = Path::create($this->dir)->join('Code.php');
        \file_put_contents((string) $file, $code('$t->now()'));
        $config = new Verification();
        $config->fuzzTimeMs = 0;
        $autoload = $this->dir . '/autoload.php';
        \file_put_contents($autoload, "<?php require_once __DIR__ . '/Port.php';\n");

        $verdict = (new DiffTester(TestPhp::binary(), $config, Path::create($this->dir)))->verify(new DiffTask(
            'App\f',
            $file,
            'Code.php',
            $code('$t->now()'),
            $code('$t->now() + 1'),
            Path::create($autoload),
        ));

        # Only a mock of the imported interface reaches the call.
        Assert::same($verdict->status, VerdictStatus::Mismatch);
        Assert::same($verdict->counterexample?->input->args[0]['type'] ?? null, 'mock');
    }

    public function lineCoverageWithAnExtension(): void
    {
        if (!\in_array('pcov', TestPhp::binary()->zendExtensions, true) && !self::hasPcov()) {
            # php.binary without pcov: the probes are the coverage (every other test).
            Assert::true(true);
            return;
        }

        $code = "<?php\nfunction f(int \$a) {\n    if (\$a > 0) {\n        return 1;\n    }\n\n    return 2;\n}\n";
        $verdict = $this->verify($code, $code, driver: CoverageDriver::Pcov);

        Assert::same($verdict->status, VerdictStatus::Equivalent, $verdict->reason);
        Assert::same([$verdict->probes, $verdict->coverage], [3, 100.0]);
    }

    private static function hasPcov(): bool
    {
        return \trim((string) \shell_exec(\escapeshellarg(TestPhp::path()) . ' -r "echo extension_loaded(\'pcov\') ? 1 : 0;"')) === '1';
    }

    /**
     * @param non-empty-string $key
     * @param positive-int $callTimeoutMs
     * @param int<0, 100> $minCoverage
     * @param non-negative-int $fuzzTimeMs
     */
    private function verify(string $original, string $changed, string $key = 'App\f', int $callTimeoutMs = 2000, int $fuzzTimeMs = 500, int $minCoverage = 90, CoverageDriver $driver = CoverageDriver::Auto): Verdict
    {
        \str_contains($original, 'namespace App;') or $key = \str_replace('App\\', '', $key);
        $file = Path::create($this->dir)->join('Code.php');
        \file_put_contents((string) $file, $original);
        $config = new Verification();
        $config->fuzzTimeMs = $fuzzTimeMs;
        $config->callTimeoutMs = $callTimeoutMs;
        $config->minBranchCoverage = $minCoverage;
        $config->coverageDriver = $driver;
        $tester = new DiffTester(TestPhp::binary(), $config, Path::create($this->dir));

        /** @var non-empty-string $key */
        return $tester->verify(new DiffTask($key, $file, 'Code.php', $original, $changed));
    }
}
