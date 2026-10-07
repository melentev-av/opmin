<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Verification;

use Internal\Path;
use Opmin\Module\Config\Schema;
use Opmin\Module\Project\Project;
use Opmin\Module\Verification\CandidateReport;
use Opmin\Module\Verification\CounterexampleRenderer;
use Opmin\Module\Verification\FunctionResult;
use Opmin\Module\Verification\Verifier;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The three levels on a candidate file, in-process (`opmin verify` runs it in a subprocess).
 */
#[Test]
#[Covers(Verifier::class)]
#[Covers(CandidateReport::class)]
#[Covers(FunctionResult::class)]
#[Covers(CounterexampleRenderer::class)]
final class VerifierTest
{
    private const ORIGINAL = <<<'PHP'
        <?php
        namespace App;
        function keep(int $a, int $b) { $s = $a + $b; return $s; }
        function key_of(array $a) { return array_key_exists('x', $a) ? 'yes' : 'no'; }
        function log_line(string $file, string $line) { return file_put_contents($file, $line); }
        function gone() { return 1; }
        PHP;

    private string $dir;

    /** @var list<string> */
    private array $log = [];

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-verifier-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/src/A.php', self::ORIGINAL);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function decidesEveryChangedFunction(): void
    {
        $candidate = \str_replace(
            ['$s = $a + $b; return $s;', "array_key_exists('x', \$a)", "function gone() { return 1; }"],
            ['return $a + $b;', "isset(\$a['x'])", "function added() { return 2; }"],
            self::ORIGINAL,
        );
        $candidate = \str_replace('return file_put_contents($file, $line);', 'return \file_put_contents($file, $line);', $candidate);

        $report = $this->verifier()->verify($this->file(), $candidate, counterexamples: Path::create($this->dir)->join('out'));

        Assert::false($report->accepted());
        $statuses = [];
        foreach ($report->functions as $function) {
            $statuses[$function->key] = $function->status;
        }
        Assert::same($statuses, ['App\keep' => 'diff-tested', 'App\key_of' => 'rejected', 'App\log_line' => 'rejected', 'App\gone' => 'rejected']);
        Assert::same($report->notes, ['`App\added` is new: it has no original behavior to compare with.', '`App\gone` is removed in the candidate.']);
        Assert::same(\array_map('basename', \glob($this->dir . '/out/*') ?: []), ['OpminAppKeyofCounterexampleTest.json', 'OpminAppKeyofCounterexampleTest.php']);
        Assert::string((string) \file_get_contents($this->dir . '/out/OpminAppKeyofCounterexampleTest.php'))->contains("\$result === 'yes'");
        Assert::same($report->functions[1]->toArray()['counterexample_test'], $this->dir . '/out/OpminAppKeyofCounterexampleTest.php');
        Assert::same(\array_keys($report->toArray()), ['accepted', 'syntax_error', 'phpstan_new_errors', 'tests', 'functions', 'notes']);
        Assert::true(\in_array('Differential test of App\keep', $this->log, true));
    }

    public function selectedFunctionsOnly(): void
    {
        $candidate = \str_replace('$s = $a + $b; return $s;', 'return $a + $b;', self::ORIGINAL);

        $report = $this->verifier()->verify($this->file(), $candidate, ['App\keep']);

        Assert::true($report->accepted());
        Assert::same(\array_map(static fn(FunctionResult $f): string => $f->key, $report->functions), ['App\keep']);
    }

    public function syntaxErrorStopsEverything(): void
    {
        $report = $this->verifier()->verify($this->file(), "<?php\nfunction f( {\n");

        Assert::false($report->accepted());
        Assert::string((string) $report->syntaxError)->contains('syntax error');
        Assert::same($report->functions, []);
    }

    public function projectTestsCoverWhatDifferentialTestsCannotProve(): void
    {
        $candidate = \str_replace('return file_put_contents($file, $line);', 'return \file_put_contents($file, $line);', self::ORIGINAL);
        \file_put_contents($this->dir . '/check.php', "<?php\nrequire __DIR__ . '/src/A.php';\nexit(\\App\\keep(1, 1) === 2 ? 0 : 1);\n");
        $tests = new Schema\Tests();
        $tests->runner = Schema\TestRunner::Command;
        $tests->command = \escapeshellarg(\PHP_BINARY) . ' check.php';

        $withTests = $this->verifier(tests: $tests)->verify($this->file(), $candidate, withTests: true);
        $allowed = $this->verifier(allowUnverified: true)->verify($this->file(), $candidate);

        Assert::same($withTests->tests?->success, true);
        Assert::same($withTests->runner, 'command');
        Assert::true(\in_array('No coverage map of the project\'s tests (Xdebug or pcov in php.binary): all tests are run.', $withTests->notes, true));
        Assert::true(\in_array('PHPStan is not installed (commands.phpstan): the static check is skipped.', $withTests->notes, true));
        # Without a coverage map no test is known to run the function: not proven.
        Assert::same($withTests->functions[0]->status, 'rejected');
        Assert::same($allowed->functions[0]->status, 'unverified');
        Assert::true($allowed->accepted());
        Assert::same(\file_get_contents($this->dir . '/src/A.php'), self::ORIGINAL);
    }

    public function redTestsOnTheOriginalStopTheCheck(): void
    {
        $tests = new Schema\Tests();
        $tests->runner = Schema\TestRunner::Command;
        $tests->command = 'exit 1';

        $report = $this->verifier(tests: $tests)->verify($this->file(), self::ORIGINAL, withTests: true);

        Assert::false($report->accepted());
        Assert::same($report->functions, []);
        Assert::string(\implode("\n", $report->notes))->contains('fail on the original code');
    }

    public function failingTestsOnTheCandidateRejectIt(): void
    {
        $tests = new Schema\Tests();
        $tests->runner = Schema\TestRunner::Command;
        $tests->command = \escapeshellarg(\PHP_BINARY) . ' -r ' . \escapeshellarg('exit(str_contains(file_get_contents("src/A.php"), "\$s = \$a + \$b") ? 0 : 1);');
        $candidate = \str_replace('$s = $a + $b; return $s;', 'return $a + $b;', self::ORIGINAL);

        $report = $this->verifier(tests: $tests)->verify($this->file(), $candidate, withTests: true);

        Assert::same($report->tests?->success, false);
        Assert::false($report->accepted());
        Assert::same($report->functions[0]->status, 'diff-tested');
    }

    private function file(): Path
    {
        return Path::create($this->dir)->join('src/A.php');
    }

    private function verifier(?Schema\Tests $tests = null, bool $allowUnverified = false): Verifier
    {
        $verification = new Schema\Verification();
        $verification->fuzzTimeMs = 200;
        $verification->allowUnverified = $allowUnverified;
        $this->log = [];

        return new Verifier(
            new Project(Path::create($this->dir), false, null),
            TestPhp::binary(),
            $verification,
            new Schema\Commands(),
            $tests ?? new Schema\Tests(),
            Path::create($this->dir)->join('.opmin-cache'),
            '8.1',
            function (string $message): void {
                $this->log[] = $message;
            },
        );
    }
}
