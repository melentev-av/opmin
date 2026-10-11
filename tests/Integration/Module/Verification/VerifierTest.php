<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Verification;

use Internal\Path;
use Opmin\Module\Common\Timings;
use Opmin\Module\Config\Schema;
use Opmin\Module\Progress\Event;
use Opmin\Module\Progress\Listener;
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
        $reasons = \array_map(static fn(FunctionResult $f): string => $f->reason, $report->functions);
        Assert::same($reasons[0], '');
        Assert::string($reasons[1])->startsWith('differential test: calls[0].value: string "yes"');
        Assert::same($reasons[2], 'not proven: side effects (io): only the project\'s tests can prove the change');
        Assert::same($reasons[3], 'not proven: cannot be called: `App\gone` is not in the file.');
        $json = (string) \file_get_contents($this->dir . '/out/OpminAppKeyofCounterexampleTest.json');
        Assert::string($json)->startsWith("{\n    \"function\": \"App\\\\key_of\",\n    \"file\": \"src/A.php\",\n");
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
        $tests->command = 'echo "phpunit: not found"; exit 1';

        $report = $this->verifier(tests: $tests)->verify($this->file(), self::ORIGINAL, withTests: true);

        Assert::false($report->accepted());
        Assert::same($report->functions, []);
        Assert::same($report->notes[\count($report->notes) - 1], 'The project\'s tests fail on the original code: fix them first. Output: phpunit: not found');
        Assert::same($report->runner, 'command');
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

    public function phpstanTellsWhichBranchesAreDead(): void
    {
        $original = "<?php\nnamespace App;\nfunction g(int \$a) {\n    if (is_int(\$a)) {\n        \$s = \$a;\n        return \$s;\n    }\n    return 0;\n}\n";
        \file_put_contents($this->dir . '/src/A.php', $original);
        $candidate = \str_replace("\$s = \$a;\n        return \$s;", 'return $a;', $original);
        $tests = new Schema\Tests();
        $tests->runner = Schema\TestRunner::Command;
        $tests->command = 'true';
        $commands = new Schema\Commands();
        # The PHAR alone: vendor/bin/phpstan would load opmin's own autoloader, which needs PHP 8.3+.
        \copy(__DIR__ . '/../../../../vendor/phpstan/phpstan/phpstan.phar', $this->dir . '/phpstan.phar');
        $commands->phpstan = 'phpstan.phar analyse --no-progress --error-format=json --level=9';
        $without = new Schema\Commands();
        $without->phpstan = null;

        $withPhpstan = $this->verifier(tests: $tests, commands: $commands, minCoverage: 100)->verify($this->file(), $candidate, withTests: true);
        $withoutPhpstan = $this->verifier(tests: $tests, commands: $without, minCoverage: 100)->verify($this->file(), $candidate, withTests: true);

        Assert::same($withPhpstan->functions[0]->verdict?->dead, 1);
        Assert::same($withPhpstan->functions[0]->status, 'diff-tested', $withPhpstan->functions[0]->reason);
        Assert::same($withoutPhpstan->functions[0]->verdict?->dead, 0);
        Assert::string($withoutPhpstan->functions[0]->reason)->contains('min_branch_coverage');
    }

    public function phpstanOnTheOriginalRunsOnceForTheSameFiles(): void
    {
        $timings = new Timings();
        $verifier = $this->verifier(commands: $this->fakePhpStan(), timings: $timings);
        $candidate = \str_replace('$s = $a + $b; return $s;', 'return $a + $b;', self::ORIGINAL);
        $broken = \str_replace('function gone() { return 1; }', 'function gone() { return 1; /* BAD */ }', self::ORIGINAL);

        $first = $verifier->verify($this->file(), $candidate, ['App\keep'], withTests: true);
        $second = $verifier->verify($this->file(), $broken, ['App\gone'], withTests: true);

        Assert::same($first->phpstan, []);
        # The cached result on the original still tells a new error from an old one.
        Assert::same(\array_column($second->phpstan, 'message'), ['BAD in A.php']);
        Assert::same($this->phpStanRuns(), [self::ORIGINAL, $candidate, $broken]);
        Assert::same($timings->toArray()['counters']['phpstan_before_cached'] ?? 0, 1);
        Assert::same($timings->toArray()['levels']['phpstan.before']['calls'] ?? 0, 1);
    }

    public function writtenCandidatesKeepTheirPhpstanResultAndAnyOtherWriteDropsIt(): void
    {
        $verifier = $this->verifier(commands: $this->fakePhpStan());
        $accepted = \str_replace('$s = $a + $b; return $s;', 'return $a + $b;', self::ORIGINAL);
        $next = \str_replace("array_key_exists('x', \$a)", "isset(\$a['x'])", $accepted);
        $other = \str_replace('function gone() { return 1; }', 'function gone() { return 2 - 1; }', $accepted);
        $b = Path::create($this->dir)->join('src/B.php');

        $verifier->verify($this->file(), $accepted, ['App\keep'], withTests: true);
        \file_put_contents((string) $this->file(), $accepted);
        $verifier->written([$this->file()]);
        $verifier->verify($this->file(), $next, ['App\key_of'], withTests: true);
        # A write elsewhere may change the errors of A.php: PHPStan on its original runs again.
        \file_put_contents((string) $b, "<?php\n");
        $verifier->written([$b]);
        $verifier->verify($this->file(), $next, ['App\key_of'], withTests: true);
        $verifier->verify($this->file(), $next, ['App\key_of'], withTests: true);
        # The written content is not the candidate checked last: its result is not taken.
        \file_put_contents((string) $this->file(), $other);
        $verifier->written([$this->file()]);
        $verifier->verify($this->file(), $next, ['App\key_of'], withTests: true);

        Assert::same($this->phpStanRuns(), [self::ORIGINAL, $accepted, $next, $accepted, $next, $next, $other, $next]);
    }

    public function filesOfOneStepAreVerifiedTogether(): void
    {
        # `App\twice()` in B.php calls `App\keep()` of A.php: its changed version runs with the changed A.
        \file_put_contents($this->dir . '/src/B.php', "<?php\nnamespace App;\nfunction twice(int \$a) { \$r = keep(\$a, \$a); return \$r; }\n");
        $original = (string) \file_get_contents($this->dir . '/src/B.php');
        $equivalentB = \str_replace('$r = keep($a, $a); return $r;', 'return keep($a, $a);', $original);
        $brokenA = \str_replace('$s = $a + $b; return $s;', 'return $a - $b;', self::ORIGINAL);
        $b = Path::create($this->dir)->join('src/B.php');
        \mkdir($this->dir . '/vendor');
        \file_put_contents($this->dir . '/vendor/autoload.php', "<?php\nrequire_once __DIR__ . '/../src/A.php';\n");

        $both = $this->verifier()->verifyAll([[$this->file(), $brokenA], [$b, $equivalentB]], [(string) $this->file() => ['App\keep'], (string) $b => ['App\twice']]);
        $alone = $this->verifier()->verifyAll([[$b, $equivalentB]]);

        $statuses = [];
        foreach ($both->functions as $function) {
            $statuses[$function->key] = $function->status;
        }
        Assert::same($statuses, ['App\keep' => 'rejected', 'App\twice' => 'rejected']);
        Assert::true($alone->accepted());
        Assert::same(\file_get_contents($this->dir . '/src/B.php'), $original);
    }

    public function baselineTestsRunOncePerVerifier(): void
    {
        $candidate = \str_replace('$s = $a + $b; return $s;', 'return $a + $b;', self::ORIGINAL);
        \file_put_contents($this->dir . '/check.php', "<?php\nrequire __DIR__ . '/src/A.php';\nexit(\\App\\keep(1, 1) === 2 ? 0 : 1);\n");
        $tests = new Schema\Tests();
        $tests->runner = Schema\TestRunner::Command;
        $tests->command = \escapeshellarg(\PHP_BINARY) . ' check.php';
        $verifier = $this->verifier(tests: $tests);

        $first = $verifier->verify($this->file(), $candidate, ['App\keep'], withTests: true);
        $second = $verifier->verify($this->file(), $candidate, ['App\keep'], withTests: true);

        Assert::true($first->accepted());
        Assert::true($second->accepted());
        Assert::count(\array_keys($this->log, 'Project tests on the original code (command)', true), 1);
        Assert::count(\array_keys($this->log, 'Project tests on the changed code', true), 2);
        # A command runner gives no coverage map: the user is warned once, at the first verification.
        Assert::count(\array_keys($this->log, 'No coverage map of the project\'s tests: every step runs the whole test suite', true), 1);
        Assert::true(\in_array('No coverage map of the project\'s tests (Xdebug or pcov in php.binary): all tests are run.', $first->notes, true));
        Assert::same($verifier->runnerName(), 'command');
        Assert::same($verifier->runAllTests()?->success, true);
    }

    public function guardPerfRejectsAnEquivalentButSlowerChange(): void
    {
        $slower = \str_replace(
            'function keep(int $a, int $b) { $s = $a + $b; return $s; }',
            'function keep(int $a, int $b) { for ($i = 0; $i < 3000; ++$i) { $a ^= 0; } return $a + $b; }',
            self::ORIGINAL,
        );
        $faster = \str_replace('function keep(int $a, int $b) { $s = $a + $b; return $s; }', 'function keep(int $a, int $b) { return $a + $b; }', self::ORIGINAL);
        $guard = new Schema\GuardPerf();
        $guard->enabled = true;

        # An equally fast change measures within the noise of a shared CI runner, which exceeds 5% at times:
        # a wider limit for it still tells it from the slower one, which is hundreds of percent slower.
        $tolerant = clone $guard;
        $tolerant->maxRegressionPercent = 50;

        $slow = $this->verifier(guardPerf: $guard)->verify($this->file(), $slower);
        $fast = $this->verifier(guardPerf: $tolerant)->verify($this->file(), $faster);
        $unguarded = $this->verifier()->verify($this->file(), $slower);

        Assert::same($slow->functions[0]->status, 'rejected');
        Assert::string($slow->functions[0]->reason)->contains('slower by')->contains('guard_perf.max_regression_percent 5');
        Assert::true(($slow->functions[0]->verdict?->perf['change_percent'] ?? 0) > 100);
        Assert::same($fast->functions[0]->status, 'diff-tested');
        Assert::true(($fast->functions[0]->verdict?->perf['change_percent'] ?? 100) < 50);
        Assert::same($unguarded->functions[0]->status, 'diff-tested');
        Assert::null($unguarded->functions[0]->verdict?->perf);
    }

    private function file(): Path
    {
        return Path::create($this->dir)->join('src/A.php');
    }

    /**
     * A PHPStan that logs the content of every analysed file and reports `BAD` in a file as an error.
     */
    private function fakePhpStan(): Schema\Commands
    {
        \file_put_contents($this->dir . '/phpstan.php', <<<'PHP'
            <?php
            $files = [];
            foreach (array_slice($argv, 1) as $arg) {
                if ($arg === 'analyse' || str_starts_with($arg, '-')) {
                    continue;
                }
                $code = (string) file_get_contents($arg);
                file_put_contents(__DIR__ . '/phpstan.log', json_encode($code) . "\n", FILE_APPEND);
                $messages = str_contains($code, 'BAD') ? [['message' => 'BAD in ' . basename($arg), 'identifier' => 'bad', 'line' => 1]] : [];
                $files[$arg] = ['messages' => $messages];
            }
            echo json_encode(['files' => $files, 'errors' => []]);
            PHP);
        $commands = new Schema\Commands();
        $commands->phpstan = 'phpstan.php analyse';

        return $commands;
    }

    /**
     * @return list<string> The content of the analysed file in every PHPStan run, in order.
     */
    private function phpStanRuns(): array
    {
        $lines = \file($this->dir . '/phpstan.log', \FILE_IGNORE_NEW_LINES) ?: [];

        return \array_map(static fn(string $line): string => (string) \json_decode($line), $lines);
    }

    private function verifier(?Schema\Tests $tests = null, bool $allowUnverified = false, ?Schema\Commands $commands = null, int $minCoverage = 90, ?Schema\GuardPerf $guardPerf = null, Timings $timings = new Timings()): Verifier
    {
        $verification = new Schema\Verification();
        $verification->fuzzTimeMs = 200;
        $verification->minBranchCoverage = $minCoverage;
        $verification->allowUnverified = $allowUnverified;
        $this->log = [];

        return new Verifier(
            new Project(Path::create($this->dir), false, null),
            TestPhp::binary(),
            $verification,
            $commands ?? new Schema\Commands(),
            $tests ?? new Schema\Tests(),
            Path::create($this->dir)->join('.opmin/cache'),
            '8.1',
            new class(function (string $line): void {
                $this->log[] = $line;
            }) implements Listener {

                /**
                 * @param \Closure(string): void $line
                 */
                public function __construct(private readonly \Closure $line) {}

                #[\Override]
                public function on(Event $event): void
                {
                    $text = $event->text();
                    $text === null or ($this->line)($text);
                }
            },
            $guardPerf,
            $timings,
        );
    }
}
