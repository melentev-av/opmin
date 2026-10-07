<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Module\Optimize\Optimizer;
use Opmin\Module\Optimize\Workspace;
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

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-optimize-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
        \file_put_contents($this->dir . '/src/Text.php', self::CODE);
        \file_put_contents($this->dir . '/.gitignore', "/runs/\n/.opmin-cache/\n");
        \file_put_contents($this->dir . '/opmin.yaml', "ignore:\n  functions: ['App\\Text::upp*']\n");
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
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
        Assert::string($reasons['App\Text::encode'] ?? '')->contains('min_gain');
        Assert::string($reasons['App\Text::save'] ?? '')->contains('not proven');
        # The rule itself respects `@opmin-ignore`: nothing to roll back.
        Assert::false(isset($reasons['App\Text::untouched']));
        Assert::string($out)->contains('opcodes (-');
        Assert::true(\is_file($this->dir . '/' . $report['patch']));
    }

    public function dirtyTreeIsRefused(): void
    {
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init');
        \file_put_contents($this->dir . '/src/Text.php', self::CODE . "\n");

        [$code, , $err] = $this->opmin('optimize');

        Assert::same($code, 2);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('The git working tree is not clean')->contains('src/Text.php');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE . "\n");
        # A dry run commits nothing: a dirty tree is fine.
        [$dryCode] = $this->opmin('optimize', '--dry-run', '--format=none');
        Assert::same($dryCode, 0);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE . "\n");
    }

    public function outsideGitTheOriginalsAreCopied(): void
    {
        [$code] = $this->opmin('optimize', '--format=none');

        Assert::same($code, 0);
        Assert::string((string) \file_get_contents($this->dir . '/src/Text.php'))->contains('\strlen($s)');
        $original = \glob($this->dir . '/runs/*/original/src/Text.php') ?: [];
        Assert::count($original, 1);
        Assert::same(\file_get_contents($original[0]), self::CODE);
    }

    public function reportIsForPeopleAndWarnsAboutAnotherEnvironment(): void
    {
        [$code] = $this->opmin('optimize', '--format=none');
        Assert::same($code, 0);
        $json = \glob($this->dir . '/runs/*/report.json') ?: [];
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
        Assert::string($markdown)
            ->contains('| `App\Text::size`<br>src/Text.php | ')
            ->contains('### Behavior not proven (1)')
            ->contains('### Excluded by the user (1)')
            ->contains('| Rector | ');

        $report['environment']['rector'] = '0.0.1';
        \file_put_contents($json[0], \json_encode($report, \JSON_THROW_ON_ERROR));
        [$again, , $err] = $this->opmin('optimize', '--format=none', '--dry-run');

        Assert::same($again, 0);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('rector changed since the run ' . \basename(\dirname($json[0])) . ': 0.0.1 →');
    }

    public function dryRunChangesNothing(): void
    {
        [$code, $out] = $this->opmin('optimize', '--dry-run', '--format=none', 'src/Text.php');

        Assert::same($code, 0);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
        Assert::string($out)->contains('dry run: files restored');
        $patch = \glob($this->dir . '/runs/*/opmin.patch') ?: [];
        Assert::count($patch, 1);
        Assert::string((string) \file_get_contents($patch[0]))->contains('+        return \strlen($s)');
    }

    public function onlyTheGivenRule(): void
    {
        [$code] = $this->opmin('optimize', '--format=none', '--rector-rule=Opmin\Rector\Rule\HoistLoopInvariantCountRector');

        Assert::same($code, 0);
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
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
        $markdown = \glob($this->dir . '/runs/*/report.md') ?: [];
        Assert::string((string) \file_get_contents($markdown[0] ?? ''))->contains('The run was interrupted');

        \exec('rm -rf ' . \escapeshellarg($this->dir . '/runs'));
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
        [$code, $out, $err] = $this->opmin('optimize', '--format=none', '--guard-perf');

        Assert::same($code, 0, $out . $err);
        $report = $this->report();
        /** @var array<string, array<string, mixed>> $functions */
        $functions = $report['functions'];
        Assert::true(\is_float($functions['App\Text::size']['time_change_percent'] ?? null) || \is_int($functions['App\Text::size']['time_change_percent'] ?? null));
    }

    public function laterStagesAreNotImplementedYet(): void
    {
        [$code, , $err] = $this->opmin('optimize', '--mutation-check');
        [$gitCode, , $gitErr] = $this->opmin('optimize', 'git@github.com:vendor/pkg.git');

        Assert::same($code, 2);
        Assert::string($err)->contains('--mutation-check is not implemented yet (after the MVP)');
        Assert::same($gitCode, 2);
        Assert::string($gitErr)->contains('stage M7');
    }

    /**
     * @return array{totals: array{ops_before: int, ops_after: int}, patch: ?string, interrupted: bool, rejected: list<array<string, mixed>>, functions: array<string, mixed>, steps: list<array{rejected: list<array{function: string, reason: string}>}>}
     */
    private function report(): array
    {
        $files = \glob($this->dir . '/runs/*/report.json') ?: [];
        Assert::count($files, 1);

        /** @var array{totals: array{ops_before: int, ops_after: int}, patch: ?string, interrupted: bool, rejected: list<array<string, mixed>>, functions: array<string, mixed>, steps: list<array{rejected: list<array{function: string, reason: string}>}>} */
        return \json_decode((string) \file_get_contents($files[0]), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function commitAll(): void
    {
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init');
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
            ]),
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
