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
        Assert::true($report['ops_after'] < $report['ops_before']);
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

    public function laterStagesAreNotImplementedYet(): void
    {
        [$code, , $err] = $this->opmin('optimize', '--review');
        [$gitCode, , $gitErr] = $this->opmin('optimize', 'git@github.com:vendor/pkg.git');

        Assert::same($code, 2);
        Assert::string($err)->contains('--review is not implemented yet (stage M5)');
        Assert::same($gitCode, 2);
        Assert::string($gitErr)->contains('stage M7');
    }

    /**
     * @return array{ops_before: int, ops_after: int, patch: string, steps: list<array{rejected: list<array{function: string, reason: string}>}>}
     */
    private function report(): array
    {
        $files = \glob($this->dir . '/runs/*/report.json') ?: [];
        Assert::count($files, 1);

        /** @var array{ops_before: int, ops_after: int, patch: string, steps: list<array{rejected: list<array{function: string, reason: string}>}>} */
        return \json_decode((string) \file_get_contents($files[0]), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function git(string ...$args): string
    {
        $out = [];
        \exec('cd ' . \escapeshellarg($this->dir) . ' && git ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        Assert::same($code, 0, \implode("\n", $out));

        return \implode("\n", $out);
    }

    /**
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function opmin(string ...$args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            \array_merge(\getenv(), [
                'OPMIN_PHP_BINARY' => TestPhp::path(),
                'GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t',
            ]),
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out, $err];
    }
}
