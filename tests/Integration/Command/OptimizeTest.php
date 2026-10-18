<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Module\Optimize\Optimizer;
use Opmin\Module\Optimize\RunHeader;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Tests\PhpUnitAdapter;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin optimize` as a process on a small project: a commit per accepted step, functions without
 * gain or proof rolled back, copy mode, dry run, a dirty tree.
 */
#[Test]
#[Covers(Optimizer::class)]
#[Covers(Workspace::class)]
#[Covers(RunHeader::class)]
final class OptimizeTest
{
    private const CODE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Text
        {
            public function size(string $s): int
            {
                return strlen($s) + count(str_split($s));
            }

            public function upper(string $s): string
            {
                return strtoupper($s);
            }

            public function encode(array $a)
            {
                return json_encode($a);
            }

            public function save(string $file, string $s): int
            {
                return (int) file_put_contents($file, $s . PHP_EOL);
            }

            /** @opmin-ignore */
            public function untouched(string $s): bool
            {
                return is_numeric($s);
            }
        }

        PHP;
    private const REVIEWED = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Text
        {
            public function size(string $s): int
            {
                return strlen($s) + count(str_split($s));
            }

            public function length(string $s): int
            {
                return strlen($s) * 2;
            }
        }

        PHP;

    private string $dir = '';

    /** @var array<string, string> Environment variables of the opmin processes on top of the inherited ones. */
    private array $env = [];

    #[BeforeTest]
    public function createProject(): void
    {
        $this->env = [];
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-optimize-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
        \file_put_contents($this->dir . '/src/Text.php', self::CODE);
        \file_put_contents($this->dir . '/.gitignore', ".opmin/\n");
        \file_put_contents($this->dir . '/opmin.yaml', "ignore:\n  functions: ['App\\Text::upp*']\n");
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir) . ' ' . \escapeshellarg($this->dir . '-bin'));
    }

    public function commitsEveryAcceptedStepAndRollsBackTheRest(): void
    {
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init');

        [$code, $out, $err] = $this->opmin('optimize', '--format=none');

        Assert::same($code, 0, $out . $err);
        $code = (string) \file_get_contents($this->dir . '/src/Text.php');
        Assert::string($code)->contains('return \strlen($s) + \count(\str_split($s));');
        # Excluded by the config, no gain, not provable (I/O) without tests, excluded by a docblock.
        Assert::string($code)->contains('return strtoupper($s);');
        Assert::string($code)->contains('return json_encode($a);');
        Assert::string($code)->contains('return (int) file_put_contents($file, $s . PHP_EOL);');
        Assert::string($code)->contains('return is_numeric($s);');
        $log = $this->git('log', '--format=%s');
        Assert::string($log)->contains('opmin: FullyQualifyGlobalCallsRector, -');
        Assert::same(\trim($this->git('status', '--porcelain')), '');

        $report = $this->report();
        Assert::true($report['totals']['ops_after'] < $report['totals']['ops_before']);
        $reasons = [];
        foreach ($report['steps'] as $step) {
            foreach ($step['rejected'] as $rejected) {
                $reasons[$rejected['function']] = $rejected['reason'];
            }
        }

        Assert::string($reasons['App\Text::upper'] ?? '')->contains('excluded by the user');
        Assert::same($reasons['App\Text::encode'] ?? '', 'saves no opcodes');
        Assert::string($reasons['App\Text::save'] ?? '')->contains('not proven');
        # The rule itself respects `@opmin-ignore`: nothing to roll back.
        Assert::false(isset($reasons['App\Text::untouched']));
        Assert::string($out)->contains('opcodes (-');
        Assert::true(\is_file($this->dir . '/' . $report['patch']));
        # The end of the run says what to do next: no runner, so the project's tests, never --allow-unverified first.
        Assert::string($out)
            ->contains('Rolled back 3: 1 behavior not proven, 1 no opcodes saved, 1 excluded by the user.')
            ->contains("What to do next:\n  - Set `tests.runner`")
            ->contains("(1)\n  ! Last resort: `--allow-unverified`")
            ->notContains('Saved most');
        Assert::same(\array_column($report['advice'], 'cause'), ['not_proven.no_runner', 'not_proven']);
    }

    /**
     * Without a terminal and without `-v` the progress is a log: every line with the time and the
     * seconds since the start, a line with the result of every step, never a line redrawn in place.
     */
    public function withoutTerminalTheProgressIsTimestampedLines(): void
    {
        [$code, $out, $err] = $this->opmin('optimize', '--format=none', '--dry-run');

        Assert::same($code, 0, $out . $err);
        $at = '  \[\d{2}:\d{2}:\d{2} \+\d+s\] ';
        Assert::string($err)->matchesRegex("~^{$at}Counting the opcodes of 1 file\(s\)$~m");
        Assert::string($err)->matchesRegex("~^{$at}Pass 1: FullyQualifyGlobalCallsRector$~m");
        Assert::string($err)->matchesRegex("~^{$at}Verifying 1 file\(s\)$~m");
        Assert::string($err)->matchesRegex("~^{$at}Pass 1: FullyQualifyGlobalCallsRector: kept \d+, rolled back \d+, -\d+ opcodes \(\d+s\)$~m");
        # The phases inside a verification are for -v.
        Assert::string($err)->notContains('Differential test of');
        Assert::string($err)->notContains("\r");
        Assert::string($out)->notContains('Pass 1:');
    }

    public function dirtyTreeIsRefused(): void
    {
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init');
        \file_put_contents($this->dir . '/src/Text.php', self::CODE . "\n");

        [$code, , $err] = $this->opmin('optimize', '--with-git');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('Files of the run have uncommitted changes')->contains('src/Text.php');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE . "\n");
        # git.require_clean: all — any file blocks the run, not only a target.
        $this->git('checkout', '--', 'src/Text.php');
        \file_put_contents($this->dir . '/notes.txt', "mine\n");
        [$allCode, , $allErr] = $this->opmin('optimize', '--set=git.require_clean=all');
        Assert::same($allCode, 2);
        Assert::string($allErr)->ignoringWhitespace(lineBreaks: true)->contains('The git working tree is not clean')->contains('notes.txt');
        \file_put_contents($this->dir . '/src/Text.php', self::CODE . "\n");
        # A dry run commits nothing: a dirty tree is fine.
        [$dryCode] = $this->opmin('optimize', '--dry-run', '--format=none');
        Assert::same($dryCode, 0);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE . "\n");
    }

    /**
     * Rector reads its first argument as a source path when such a file exists: a `process/` in the
     * project root made it change the files there instead of the targets.
     */
    public function aProcessDirectoryInTheRootIsNotTakenForTheTargets(): void
    {
        $other = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Other;\n\nfunction size(string $s): int\n{\n    return strlen($s);\n}\n";
        \mkdir($this->dir . '/process');
        \file_put_contents($this->dir . '/process/size.php', $other);

        [$code] = $this->opmin('optimize', '--format=none', 'src');

        Assert::same($code, 0);
        Assert::string((string) \file_get_contents($this->dir . '/src/Text.php'))->contains('\\strlen($s)');
        Assert::same(\file_get_contents($this->dir . '/process/size.php'), $other);
    }

    public function outsideGitTheOriginalsAreCopied(): void
    {
        [$code] = $this->opmin('optimize', '--format=none');

        Assert::same($code, 0);
        Assert::string((string) \file_get_contents($this->dir . '/src/Text.php'))->contains('\strlen($s)');
        $original = \glob($this->dir . '/.opmin/runs/*/original/src/Text.php') ?: [];
        Assert::count($original, 1);
        Assert::same(\file_get_contents($original[0]), self::CODE);
    }

    public function reportIsForPeopleAndWarnsAboutAnotherEnvironment(): void
    {
        [$code] = $this->opmin('optimize', '--format=none');
        Assert::same($code, 0);
        $json = \glob($this->dir . '/.opmin/runs/*/report.json') ?: [];
        Assert::count($json, 1);
        /** @var array<string, mixed> $report */
        $report = \json_decode((string) \file_get_contents($json[0]), true, flags: \JSON_THROW_ON_ERROR);
        $markdown = (string) \file_get_contents(\dirname($json[0]) . '/report.md');

        Assert::same($report['schema'], 1);
        Assert::array($report['environment'])->hasKeys('php', 'php_target', 'optimizer_hash', 'opmin', 'rector', 'phpstan');
        Assert::same(\array_keys($report['functions']), ['App\Text::size']);
        Assert::same($report['functions']['App\Text::size']['status'], 'diff-tested');
        Assert::same($report['functions']['App\Text::size']['diff_coverage'], 100);
        Assert::same(\array_column($report['rejected'], 'kind', 'function'), [
            'App\Text::upper' => 'ignored',
            'App\Text::encode' => 'no_gain',
            'App\Text::save' => 'not_proven',
        ]);
        Assert::same(\array_column($report['rejected'], 'cause', 'function')['App\Text::save'], 'not_proven.no_runner');
        Assert::string($markdown)
            ->contains('| `App\Text::size`<br>src/Text.php | ')
            ->contains('### Behavior not proven (1)')
            ->contains("## What to do next\n\n- Set `tests.runner`")
            ->contains('### Excluded by the user (1)')
            ->contains('| Rector | ');

        $report['environment']['rector'] = '0.0.1';
        \file_put_contents($json[0], \json_encode($report, \JSON_THROW_ON_ERROR));
        [$again, , $err] = $this->opmin('optimize', '--format=none', '--dry-run');

        Assert::same($again, 0);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('rector changed since the run ' . \basename(\dirname($json[0])) . ': 0.0.1 →');
    }

    public function reportShowsWhereTheTimeWent(): void
    {
        [$code, , $err] = $this->opmin('optimize', '--format=none', '-v');
        Assert::same($code, 0);
        $json = \glob($this->dir . '/.opmin/runs/*/report.json') ?: [];
        /** @var array{timings: array{wall_seconds: float, levels: array<string, mixed>, counters: array<string, int>}, steps: list<array{accepted: list<array{checks: list<array<string, mixed>>}>}>} $report */
        $report = \json_decode((string) \file_get_contents($json[0]), true, flags: \JSON_THROW_ON_ERROR);

        Assert::true($report['timings']['wall_seconds'] > 0);
        Assert::array($report['timings']['levels'])->hasKeys('once.count', 'once.references', 'rector', 'count', 'diff_test', 'diff_test.start', 'diff_test.inputs');
        Assert::true($report['timings']['counters']['steps'] > 0);
        Assert::true($report['timings']['counters']['verify_rounds'] > 0);
        Assert::numeric($report['steps'][0]['accepted'][0]['checks'][0]['seconds']);
        Assert::string($err)->contains('Time: ')->contains('diff_test.inputs');
    }

    /**
     * The cheap checks (gain, ignore, eval) come first: a function they reject never reaches PHPStan,
     * the project's tests or a differential test. The file whose change passes them is the control:
     * the expensive checks do run on it.
     */
    public function functionsRejectedByCheapChecksNeverReachTheExpensiveOnes(): void
    {
        \file_put_contents($this->dir . '/src/Text.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Text
            {
                public function upper(string $s): string
                {
                    return strtoupper($s);
                }

                public function encode(array $a)
                {
                    return json_encode($a);
                }

                public function evaluated(string $s): int
                {
                    return strlen($s) + (int) eval('return 1;');
                }
            }

            PHP);
        \file_put_contents($this->dir . '/src/Size.php', "<?php\n\nnamespace App;\n\nfunction size(string \$s): int\n{\n    return strlen(\$s) + count(str_split(\$s));\n}\n");
        \file_put_contents($this->dir . '/phpstan.php', <<<'PHP'
            <?php
            if (in_array('analyse', $argv, true)) {
                foreach (array_slice($argv, 1) as $arg) {
                    is_file($arg) && !str_starts_with($arg, '-') and file_put_contents(__DIR__ . '/phpstan.log', basename($arg) . "\n", FILE_APPEND);
                }
            }
            echo json_encode(['files' => [], 'errors' => []]);
            PHP);
        \file_put_contents($this->dir . '/tests.php', "<?php\nfile_put_contents(__DIR__ . '/tests.log', \"run\\n\", FILE_APPEND);\n");
        \file_put_contents($this->dir . '/opmin.yaml', \implode("\n", [
            'ignore:',
            "  functions: ['App\\Text::upp*']",
            'commands:',
            "  phpstan: 'phpstan.php analyse'",
            'tests:',
            '  runner: command',
            "  command: '" . \PHP_BINARY . " tests.php'",
            '',
        ]));
        \file_put_contents($this->dir . '/.gitignore', ".opmin/\n*.log\n");
        $this->commitAll();

        [$code, $out, $err] = $this->opmin('optimize', '--format=none', '--rector-rule=fqn');

        Assert::same($code, 0, $out . $err);
        $report = $this->report();
        # PHPStan saw Size.php only (on the original and on the candidate), never Text.php.
        Assert::same(\array_unique(\file($this->dir . '/phpstan.log', \FILE_IGNORE_NEW_LINES) ?: []), ['Size.php']);
        /** @var array{counters: array<string, int>} $timings */
        $timings = $report['timings'];
        Assert::same($timings['counters']['verify_files'] ?? 0, $timings['counters']['verify_rounds'] ?? -1);
        # The tests ran on the original code, once per verification round of Size.php and at the end: never for Text.php.
        Assert::count(\file($this->dir . '/tests.log') ?: [], 2 + $timings['counters']['verify_rounds']);
        $reasons = [];
        foreach ($report['steps'] as $step) {
            foreach ($step['rejected'] as $rejected) {
                $reasons[$rejected['function']] = $rejected['reason'];
            }
        }

        Assert::string($reasons['App\Text::upper'] ?? '')->contains('excluded by the user');
        Assert::same($reasons['App\Text::encode'] ?? '', 'saves no opcodes');
        Assert::string($reasons['App\Text::evaluated'] ?? '')->contains('uses eval or include');
        Assert::string((string) \file_get_contents($this->dir . '/src/Size.php'))->contains('return \strlen($s) + \count(\str_split($s));');
    }

    public function dryRunChangesNothing(): void
    {
        [$code, $out] = $this->opmin('optimize', '--dry-run', '--format=none', 'src/Text.php');

        Assert::same($code, 0);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
        Assert::string($out)->contains('dry run: files restored');
        $patch = \glob($this->dir . '/.opmin/runs/*/opmin.patch') ?: [];
        Assert::count($patch, 1);
        Assert::string((string) \file_get_contents($patch[0]))->contains('+        return \strlen($s)');
    }

    /**
     * `__METHOD__` in a method is its name, whatever the line (#52); `__LINE__`, a backtrace, the trace of
     * an exception and `__FUNCTION__` in a closure (`{closure:App\Lines::m():12}` since PHP 8.4) are not.
     */
    public function onlyCodeThatSeesItsLineIsNeverChanged(): void
    {
        \file_put_contents($this->dir . '/src/Lines.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Lines
            {
                public function withMethod(string $s): int
                {
                    if ($s === '') {
                        throw new \InvalidArgumentException(__METHOD__ . '(): empty string');
                    }

                    return strlen($s);
                }

                public function withLine(string $s): int
                {
                    return strlen($s) + __LINE__;
                }

                public function withBacktrace(string $s): int
                {
                    return strlen($s) + count(debug_backtrace());
                }

                public function withTrace(string $s): string
                {
                    return strlen($s) . (new \Exception())->getTraceAsString();
                }

                public function inClosure(array $xs): array
                {
                    return array_map(fn(string $x): string => __FUNCTION__ . strlen($x), $xs);
                }
            }

            PHP);

        [$code, $out, $err] = $this->opmin('optimize', '--dry-run', '--format=none', '--rector-rule=fqn', 'src/Lines.php');

        Assert::same($code, 0, $out . $err);
        $report = $this->report();
        $reasons = [];
        foreach ($report['steps'] as $step) {
            foreach ($step['rejected'] as $rejected) {
                $reasons[$rejected['function']] = $rejected['reason'];
            }
        }

        Assert::same(\array_keys($report['functions']), ['App\Lines::withMethod'], \print_r($reasons, true));
        foreach (['withLine', 'withBacktrace', 'withTrace', 'inClosure'] as $method) {
            Assert::string($reasons["App\\Lines::{$method}"] ?? '')->contains('depends on line numbers: never changed');
        }
    }

    public function aTypoInAnIgnoreMarkIsAWarningAndExcludesNothing(): void
    {
        \copy(__DIR__ . '/../../Fixtures/Ignore/Typo.php', $this->dir . '/src/Typo.php');

        [$code, $out, $err] = $this->opmin('optimize', '--dry-run', '--format=none', '--rector-rule=fqn', 'src/Typo.php');

        Assert::same($code, 0, $out . $err);
        Assert::string($out . $err)->ignoringWhitespace(lineBreaks: true)
            ->contains('src/Typo.php:9: `@opmin-ignore` lists `fqm`, which names no known rule, so it excludes nothing. Did you mean `fqn`?');
        $report = $this->report();
        Assert::count($report['warnings'], 1);
        Assert::string($report['warnings'][0])->contains('`fqm`');
        # The typo excludes nothing (guessing the rule would be worse); a full class name excludes in both forms.
        $patch = \glob($this->dir . '/.opmin/runs/*/opmin.patch') ?: [];
        Assert::count($patch, 1);
        Assert::same(\substr_count((string) \file_get_contents($patch[0]), '+        return \count($a);'), 1);
        Assert::string((string) \file_get_contents($patch[0]))->contains("     public function typo(array \$a): int\n     {\n-        return count(\$a);\n+        return \\count(\$a);");
    }

    public function redTestsOnTheOriginalStopTheRunWithTheReason(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', "tests:\n  runner: command\n  command: 'echo \"vendor/bin/phpunit: not found\"; exit 127'\n");

        [$code, , $err] = $this->opmin('optimize', '--format=none');
        [$none] = $this->opmin('optimize', '--format=none', '--dry-run', '--set=tests.runner=none');

        Assert::same($code, 1);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)
            ->contains('The project\'s tests fail on the original code')
            ->contains('vendor/bin/phpunit: not found');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
        Assert::same($none, 0);
    }

    public function onlyTheGivenRule(): void
    {
        [$code] = $this->opmin('optimize', '--format=none', '--rector-rule=Opmin\Rector\Rule\HoistLoopInvariantCountRector');

        Assert::same($code, 0);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
    }

    public function theGrowthOfRulesCheaperToRunRaisesTheBaseline(): void
    {
        \file_put_contents($this->dir . '/src/Path.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Path
            {
                public function join(string $dir, string $name): string
                {
                    return $dir . '/' . $name . '.php';
                }
            }

            PHP);
        $this->commitAll();
        [$code] = $this->opmin('baseline');
        Assert::same($code, 0);
        $this->git('add', 'opmin.baseline.json');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'baseline');
        /** @var array{functions: array<string, array{ops: int}>} $before */
        $before = \json_decode((string) \file_get_contents($this->dir . '/opmin.baseline.json'), true, flags: \JSON_THROW_ON_ERROR);

        [$code, $out, $err] = $this->opmin('optimize', '--format=none', '--rector-rule=interpolate');

        Assert::same($code, 0, $out . $err);
        Assert::string((string) \file_get_contents($this->dir . '/src/Path.php'))->contains('return "{$dir}/{$name}.php";');
        /** @var array{functions: array<string, array{ops: int}>} $after */
        $after = \json_decode((string) \file_get_contents($this->dir . '/opmin.baseline.json'), true, flags: \JSON_THROW_ON_ERROR);
        $grown = $after['functions']['App\\Path::join']['ops'] - $before['functions']['App\\Path::join']['ops'];
        Assert::true($grown > 0, "the baseline of App\\Path::join grew by {$grown}");
        Assert::same($this->git('log', '-1', '--format=%s'), 'opmin: raise the baseline by the rules cheaper to run');
        Assert::same($this->git('status', '--porcelain', '--', 'opmin.baseline.json'), '');
        [$code, $out] = $this->opmin('check', '--all');
        Assert::same($code, 0, $out);
    }

    public function reviewAppliesWhatIsAcceptedAndRemembersWhatIsDeclined(): void
    {
        \file_put_contents($this->dir . '/src/Text.php', self::REVIEWED);
        $this->commitAll();

        [$code, $out, $err] = $this->opmin(['optimize', '--format=none', '--review'], "n\ny\n");

        Assert::same($code, 0, $out . $err);
        Assert::string($err)
            ->contains('App\Text::size — src/Text.php')
            ->contains('-        return strlen($s) + count(str_split($s));')
            ->contains('rule fqn, checks: diff-tested 100%')
            ->contains('Apply? [y]es, [n]o');
        $code = (string) \file_get_contents($this->dir . '/src/Text.php');
        Assert::string($code)->contains('return strlen($s) + count(str_split($s));');
        Assert::string($code)->contains('return \strlen($s) * 2;');
        Assert::same(
            (string) \file_get_contents($this->dir . '/opmin.baseline.yaml'),
            "# Changes declined in `opmin optimize --review`: opmin does not propose them again.\n"
            . "# Remove an entry to let opmin try the change again.\n"
            . "rejected:\n  - { function: 'App\\Text::size', rule: fqn }\n",
        );
        Assert::same(\trim($this->git('status', '--porcelain')), '');
        Assert::string($this->git('log', '-1', '--format=%s'))->contains('opmin: remember the changes declined in the review');
        $report = $this->report();
        Assert::same(\array_column($report['rejected'], 'kind', 'function'), ['App\Text::size' => 'review']);

        # Not proposed again, also without --review.
        [$again, $againOut, $againErr] = $this->opmin(['optimize', '--format=none', '--review'], '');

        Assert::same($again, 0, $againOut . $againErr);
        Assert::string($againErr)->notContains('Apply?');
        Assert::string((string) \file_get_contents($this->dir . '/src/Text.php'))->contains('return strlen($s) + count(str_split($s));');
    }

    public function reviewQuitEndsTheRunAndAllAcceptsTheRule(): void
    {
        \file_put_contents($this->dir . '/src/Text.php', self::REVIEWED);
        $this->commitAll();

        [$code, $out, $err] = $this->opmin(['optimize', '--format=none', '--review'], "q\n");

        Assert::same($code, 0, $out . $err);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::REVIEWED);
        Assert::false(\is_file($this->dir . '/opmin.baseline.yaml'));
        $report = $this->report();
        Assert::true($report['interrupted']);
        $markdown = \glob($this->dir . '/.opmin/runs/*/report.md') ?: [];
        Assert::string((string) \file_get_contents($markdown[0] ?? ''))->contains('The run was interrupted');
        # The same report lies in the root of .opmin/, named after the run.
        $copy = $this->dir . '/.opmin/report_' . \basename(\dirname($markdown[0] ?? '')) . '.md';
        Assert::same(\file_get_contents($copy), \file_get_contents($markdown[0] ?? ''));
        Assert::string($out . $err)->ignoringWhitespace(lineBreaks: true)->contains('Report: .opmin/report_');

        \exec('rm -rf ' . \escapeshellarg($this->dir . '/.opmin/runs'));
        [$all, $allOut, $allErr] = $this->opmin(['optimize', '--format=none', '--review'], "a\n");

        Assert::same($all, 0, $allOut . $allErr);
        Assert::same(\substr_count($allErr, 'Apply?'), 1);
        Assert::string((string) \file_get_contents($this->dir . '/src/Text.php'))->contains('return \strlen($s) + \count(\str_split($s));');
    }

    public function reviewWithoutInteractionAppliesEverything(): void
    {
        \file_put_contents($this->dir . '/src/Text.php', self::REVIEWED);
        $this->commitAll();

        [$code, $out, $err] = $this->opmin(['optimize', '--format=none', '--review', '--no-interaction']);

        Assert::same($code, 0, $out . $err);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('--review needs an interactive session');
        Assert::string((string) \file_get_contents($this->dir . '/src/Text.php'))->contains('return \strlen($s) * 2;');
    }

    public function guardPerfMeasuresTheKeptChanges(): void
    {
        # The timing of a tiny function on a shared runner is noise: a huge allowed slowdown keeps the change,
        # so its measurement lands in the report however the run went.
        [$code, $out, $err] = $this->opmin('optimize', '--format=none', '--guard-perf', '--set=guard_perf.max_regression_percent=1000000');

        Assert::same($code, 0, $out . $err);
        $report = $this->report();
        /** @var array<string, array<string, mixed>> $functions */
        $functions = $report['functions'];
        Assert::true(\is_float($functions['App\Text::size']['time_change_percent'] ?? null) || \is_int($functions['App\Text::size']['time_change_percent'] ?? null));
    }

    /**
     * With tests in processes of their own, guard-perf times the kept changes afterwards, one by one.
     */
    public function guardPerfMeasuresTheKeptChangesOfParallelTests(): void
    {
        [$code, $out, $err] = $this->opmin('optimize', '--format=none', '--guard-perf', '--jobs=2', '--set=guard_perf.max_regression_percent=1000000');

        Assert::same($code, 0, $out . $err);
        $report = $this->report();
        /** @var array<string, array<string, mixed>> $functions */
        $functions = $report['functions'];
        Assert::true(\is_float($functions['App\Text::size']['time_change_percent'] ?? null) || \is_int($functions['App\Text::size']['time_change_percent'] ?? null));
        /** @var array{levels: array<string, mixed>} $timings */
        $timings = $report['timings'];
        Assert::array($timings['levels'])->hasKeys('diff_test.pool', 'diff_test.perf');
    }

    public function planSaysWhatTheRunWouldUseAndChangesNothing(): void
    {
        \file_put_contents($this->dir . '/opmin.yaml', "tests:\n  runner: command\n  command: 'exit 1'\n");
        $this->commitAll();

        [$code, $out, $err] = $this->opmin('optimize', '--plan', '--format=none');

        Assert::same($code, 0, $out . $err);
        Assert::string($err)
            ->contains('Optimizing 1 file(s) of ')
            ->contains('plan: nothing is changed or tested.')
            ->contains('  Functions: ')
            ->contains('  Rules: FullyQualifyGlobalCallsRector')
            ->contains('  Tests: command (tests.command): the whole suite on every step')
            ->contains('  Coverage driver: ')
            ->contains('  PHPStan: none (not installed in the project)')
            ->contains('  Formatter: no formatter (commands.format: none)')
            ->contains('  Differential tests: ');
        # The failing command never ran: the run would stop at red tests.
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('Every step runs the whole test suite: tests.command is a plain command');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
        Assert::false(\is_dir($this->dir . '/.opmin/runs'));
        Assert::same(\trim($this->git('status', '--porcelain')), '');
        Assert::same(\trim($this->git('rev-list', '--count', 'HEAD')), '1');
    }

    public function planNamesTheDetectedRunnerAndWarnsOnlyWithoutACoverageDriver(): void
    {
        \file_put_contents($this->dir . '/phpunit.xml', "<phpunit/>\n");

        [$code, , $err] = $this->opmin('optimize', '--plan', '--format=none');

        Assert::same($code, 0, $err);
        $driver = PhpUnitAdapter::hasCoverageDriver(TestPhp::binary());
        Assert::string($err)->contains($driver
            ? '  Tests: phpunit, the tests that cover a changed function'
            : '  Tests: phpunit, the whole suite on every step');
        # The warning wraps at the width of the terminal.
        $flat = (string) \preg_replace('/\s+/', ' ', $err);
        Assert::same(\str_contains($flat, 'neither pcov nor Xdebug, so there is no coverage map'), !$driver);
        Assert::false(\is_dir($this->dir . '/.opmin/runs'));
    }

    /**
     * Staged files and no commit (#57): refused like a dirty tree, before the run directory exists.
     */
    public function aRepositoryWithoutCommitsIsRefusedBeforeTheRun(): void
    {
        $this->git('init', '-q');
        $this->git('add', '.');

        [$code, $out, $err] = $this->opmin('optimize', '--dry-run');
        [$realCode, , $realErr] = $this->opmin('optimize');

        Assert::same($code, 2, $out . $err);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)
            ->contains('The git repository has no commits yet: commit the files first (every accepted step becomes a commit), or run outside git.')
            ->notContains('optimize [--config');
        Assert::same($realCode, 2, $realErr);
        Assert::same(\glob($this->dir . '/.opmin/runs/*') ?: [], []);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
    }

    /**
     * A failure in the middle of the run is an error of the command, not a trace with the usage line;
     * a dry run still restores the files.
     */
    public function aFailureDuringTheRunIsAnErrorWithExitOne(): void
    {
        $this->commitAll();
        # HEAD is read after the first counting and after every step: fail after the first step changed the file.
        $this->failingGit('rev-parse HEAD', skip: 1);

        [$code, $out, $err] = $this->opmin('optimize', '--format=none', '--dry-run');

        Assert::same($code, 1, $out . $err);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)
            ->contains('[ERROR] git rev-parse failed: simulated failure')
            ->contains('The run stopped: what it did so far is in .opmin/runs/')
            ->notContains('In Workspace.php')
            ->notContains('optimize [--config');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
    }

    public function jobsMustBeANumber(): void
    {
        [$code, , $err] = $this->opmin('optimize', '--jobs=many');

        Assert::same($code, 2);
        Assert::string($err)->contains('--jobs takes a number');
    }

    public function packageOptionsNeedAGitUrl(): void
    {
        [$code, , $err] = $this->opmin('optimize', '--ref=v1.0.0');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('--ref is for a git package');
    }

    /**
     * @return array{totals: array{ops_before: int, ops_after: int}, warnings: list<string>, patch: ?string, interrupted: bool, rejected: list<array<string, mixed>>, functions: array<string, mixed>, steps: list<array{rejected: list<array{function: string, reason: string}>}>}
     */
    private function report(): array
    {
        $files = \glob($this->dir . '/.opmin/runs/*/report.json') ?: [];
        Assert::count($files, 1);

        /** @var array{totals: array{ops_before: int, ops_after: int}, warnings: list<string>, patch: ?string, interrupted: bool, rejected: list<array<string, mixed>>, functions: array<string, mixed>, steps: list<array{rejected: list<array{function: string, reason: string}>}>} */
        return \json_decode((string) \file_get_contents($files[0]), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function commitAll(): void
    {
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init');
    }

    /**
     * Puts a `git` first in the PATH of opmin that fails the given command line after it ran it
     * `$skip` times, and runs the real git for the rest.
     */
    private function failingGit(string $command, int $skip = 0): void
    {
        $real = \trim((string) \shell_exec('command -v git'));
        $bin = $this->dir . '-bin';
        \mkdir($bin);
        \file_put_contents($bin . '/git', \sprintf(
            <<<'SH'
                #!/bin/sh
                if [ "$*" = %1$s ]; then
                  echo x >> %2$s
                  if [ "$(wc -l < %2$s)" -gt %3$d ]; then
                    echo 'simulated failure' >&2
                    exit 128
                  fi
                fi
                exec %4$s "$@"

                SH,
            \escapeshellarg($command),
            \escapeshellarg($bin . '/calls'),
            $skip,
            \escapeshellarg($real),
        ));
        \chmod($bin . '/git', 0755);
        $this->env['PATH'] = $bin . \PATH_SEPARATOR . (string) \getenv('PATH');
    }

    private function git(string ...$args): string
    {
        $out = [];
        \exec('cd ' . \escapeshellarg($this->dir) . ' && git ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        Assert::same($code, 0, \implode("\n", $out));

        return \implode("\n", $out);
    }

    /**
     * @param string|list<string> $command The first argument, or all of them with the standard input after.
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function opmin(string|array $command, string ...$args): array
    {
        [$args, $stdin] = \is_array($command) ? [$command, $args[0] ?? null] : [[$command, ...$args], null];
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', ...$args],
            [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            \array_merge(\getenv(), [
                'OPMIN_PHP_BINARY' => TestPhp::path(),
                'GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t',
            ], $this->env),
        );
        if ($stdin !== null) {
            \fwrite($pipes[0], $stdin);
            \fclose($pipes[0]);
        }

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out, $err];
    }
}
