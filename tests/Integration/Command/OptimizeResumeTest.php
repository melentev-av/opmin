<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Module\Optimize\Optimizer;
use Opmin\Module\Optimize\RunState;
use Opmin\Module\Optimize\Workspace;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin optimize --resume`: a run stopped by Ctrl+C or killed in the middle of a step ends with the
 * same code, commits and report as a run that was never interrupted.
 */
#[Test]
#[Covers(RunState::class)]
#[Covers(Optimizer::class)]
#[Covers(Workspace::class)]
final class OptimizeResumeTest
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

            public function length(string $s): int
            {
                return strlen($s) * 2;
            }

            public function sum(array $a): int
            {
                $t = 0;
                for ($i = 0; $i < \count($a); $i++) {
                    $t += (int) ($a[$i] ?? 0);
                }

                return $t;
            }
        }

        PHP;

    /** @var list<string> */
    private array $dirs = [];

    #[AfterTest]
    public function removeProjects(): void
    {
        foreach ($this->dirs as $dir) {
            \exec('rm -rf ' . \escapeshellarg($dir));
        }
    }

    #[BeforeTest]
    public function reset(): void
    {
        $this->dirs = [];
    }

    public function killedRunResumesToTheSameResult(): void
    {
        $expected = $this->project();
        [$code, $out, $err] = $this->opmin($expected, ['optimize', '--format=none']);
        Assert::same($code, 0, $out . $err);
        $dir = $this->project();

        $this->runUntilFirstStep($dir, 9);
        Assert::same(\glob($dir . '/runs/*/report.json'), []);
        [$resumed, $resumedOut, $resumedErr] = $this->opmin($dir, ['optimize', '--resume']);

        Assert::same($resumed, 0, $resumedOut . $resumedErr);
        Assert::string($resumedErr)->contains('Resuming runs/');
        $this->assertSameResult($dir, $expected);
    }

    public function stoppedRunResumesToTheSameResult(): void
    {
        $expected = $this->project();
        $this->opmin($expected, ['optimize', '--format=none']);
        $dir = $this->project();

        [$code, $out] = $this->runUntilFirstStep($dir, 2);

        Assert::same($code, 130);
        Assert::string($out)->contains('Stopping: the current step is dropped');
        $report = $this->report($dir);
        Assert::true($report['interrupted']);
        Assert::same(\trim($this->git($dir, 'status', '--porcelain')), '');

        [$resumed, $resumedOut, $resumedErr] = $this->opmin($dir, ['optimize', '--resume']);

        Assert::same($resumed, 0, $resumedOut . $resumedErr);
        $this->assertSameResult($dir, $expected);
        [$again, , $againErr] = $this->opmin($dir, ['optimize', '--resume']);
        Assert::same($again, 2);
        Assert::string($againErr)->contains('No interrupted run');
    }

    public function nothingToResume(): void
    {
        $dir = $this->project();

        [$code, , $err] = $this->opmin($dir, ['optimize', '--resume']);
        [$withPaths, , $pathsErr] = $this->opmin($dir, ['optimize', 'src', '--resume']);

        Assert::same($code, 2);
        Assert::string($err)->contains('No interrupted run in runs/');
        Assert::same($withPaths, 2);
        Assert::string($pathsErr)->contains('do not pass paths');
    }

    private function assertSameResult(string $dir, string $expected): void
    {
        Assert::same(\file_get_contents($dir . '/src/Text.php'), \file_get_contents($expected . '/src/Text.php'));
        Assert::same($this->git($dir, 'log', '--format=%s'), $this->git($expected, 'log', '--format=%s'));
        Assert::same(\trim($this->git($dir, 'status', '--porcelain')), '');
        $report = $this->report($dir);
        $expectedReport = $this->report($expected);
        Assert::false($report['interrupted']);
        Assert::same($report['totals'], $expectedReport['totals']);
        Assert::same(\array_keys($report['functions']), \array_keys($expectedReport['functions']));
    }

    /**
     * Starts `opmin optimize` and sends the signal once the first step is saved.
     *
     * @return array{int, string} Exit code (-1 when killed), stderr.
     */
    private function runUntilFirstStep(string $dir, int $signal): array
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', 'optimize', '--format=none'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            $this->env(),
        );
        $deadline = \microtime(true) + 120;
        while (\microtime(true) < $deadline) {
            $states = \glob($dir . '/runs/*/state.json') ?: [];
            /** @var mixed $state */
            $state = $states === [] ? null : \json_decode((string) @\file_get_contents($states[0]), true);
            if (\is_array($state) && \is_array($state['steps'] ?? null) && \count($state['steps']) >= 1) {
                break;
            }

            \usleep(20_000);
        }

        \proc_terminate($process, $signal);
        $err = (string) \stream_get_contents($pipes[2]);
        $code = \proc_close($process);

        return [$code, $err];
    }

    private function project(): string
    {
        $dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-resume-' . \bin2hex(\random_bytes(4));
        $this->dirs[] = $dir;
        \mkdir($dir . '/src', 0777, true);
        \file_put_contents($dir . '/composer.json', '{"require": {"php": ">=8.1"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
        \file_put_contents($dir . '/src/Text.php', self::CODE);
        \file_put_contents($dir . '/.gitignore', "/runs/\n/.opmin-cache/\n");
        $this->git($dir, 'init', '-q');
        $this->git($dir, 'add', '.');
        $this->git($dir, 'commit', '-q', '-m', 'init');

        return $dir;
    }

    /**
     * @return array{totals: array<string, mixed>, interrupted: bool, functions: array<string, mixed>}
     */
    private function report(string $dir): array
    {
        $files = \glob($dir . '/runs/*/report.json') ?: [];
        Assert::count($files, 1);

        /** @var array{totals: array<string, mixed>, interrupted: bool, functions: array<string, mixed>} */
        return \json_decode((string) \file_get_contents($files[0]), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function git(string $dir, string ...$args): string
    {
        $out = [];
        \exec('cd ' . \escapeshellarg($dir) . ' && git -c user.name=t -c user.email=t@t ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        Assert::same($code, 0, \implode("\n", $out));

        return \implode("\n", $out);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function opmin(string $dir, array $args): array
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/opmin', '--no-ansi', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            $this->env(),
        );
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out, $err];
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return \array_merge(\getenv(), [
            'OPMIN_PHP_BINARY' => TestPhp::path(),
            'GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t',
        ]);
    }
}
