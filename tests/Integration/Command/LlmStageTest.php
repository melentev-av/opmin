<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Command;

use Opmin\Command\ApplyCandidate;
use Opmin\Command\LlmContext;
use Opmin\Command\LlmFinish;
use Opmin\Command\LlmTargets;
use Opmin\Module\Llm\Session;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The LLM stage as the skill drives it, as processes on a small git project: `llm:targets` →
 * `llm:context` → `apply-candidate` (accepted with a commit, or rejected with nothing written) →
 * `llm:finish`.
 */
#[Test]
#[Covers(LlmTargets::class)]
#[Covers(LlmContext::class)]
#[Covers(ApplyCandidate::class)]
#[Covers(LlmFinish::class)]
#[Covers(Session::class)]
final class LlmStageTest
{
    private const CODE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Text
        {
            public function slug(string $title): string
            {
                $lower = strtolower(trim($title));
                $slug = preg_replace('/[^a-z0-9]+/', '-', $lower);

                return trim((string) $slug, '-');
            }

            public function kind(string $v): string
            {
                if (in_array($v, ['a', 'b'], true)) {
                    return 'ab';
                }

                return in_array($v, ['c', 'd'], true) ? 'cd' : 'other';
            }

            public function names(array $vars): array
            {
                $first = trim((string) ($vars['first'] ?? ''));
                $last = trim((string) ($vars['last'] ?? ''));

                return compact('first', 'last');
            }

            /** @opmin-ignore llm */
            public function kept(string $s): string
            {
                return strtoupper(trim(strrev(trim($s))));
            }

            public function evaluated(string $code): mixed
            {
                return eval(trim(strrev(trim($code))));
            }
        }

        PHP;

    private string $dir = '';

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-llm-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/src', 0777, true);
        \file_put_contents($this->dir . '/composer.json', '{"require": {"php": ">=8.1"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
        \file_put_contents($this->dir . '/src/Text.php', self::CODE);
        \file_put_contents($this->dir . '/.gitignore', "/runs/\n/.opmin-cache/\n");
        \file_put_contents($this->dir . '/opmin.yaml', "commands:\n  format: none\nllm:\n  attempts_per_function: 2\n");
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'init');
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function winningCandidateIsCommittedAndTheSessionFinishes(): void
    {
        $targets = $this->json('llm:targets', '--format=json');
        # Not targets: excluded for the LLM stage by a docblock, `eval` (never changed).
        Assert::same(\array_column($targets['targets'], 'key'), ['App\Text::names', 'App\Text::slug', 'App\Text::kind']);
        Assert::same($targets['attempts_per_function'], 2);

        $context = $this->json('llm:context', 'App\Text::names', '--format=json');
        Assert::string($context['source'])->contains('public function names(array $vars): array');
        Assert::string((string) $context['functions']['App\Text::names']['listing'])->contains('RECV');
        Assert::same(\array_keys($context['flags']), ['compact']);
        Assert::string($context['restrictions']['variables'])->contains('Do not remove, rename or inline local variables');
        Assert::same($context['readability']['forbid_patterns'], ['nested_ternary', 'assignment_in_condition', 'goto']);
        [, $text] = $this->opmin('llm:context', 'App\Text::slug');
        Assert::string($text)->contains('## Opcodes of App\Text::slug')->contains('INIT_NS_FCALL_BY_NAME')->contains('Attempts left: 2.');

        $candidate = $this->candidate(<<<'PHP'
                public function slug(string $title): string
                {
                    return \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower(\trim($title))), '-');
                }
            PHP);
        [$code, $out, $err] = $this->opmin('apply-candidate', 'App\Text::slug', $candidate, '--format=json');

        Assert::same($code, 0, $out . $err);
        /** @var array{attempt: array{accepted: bool, gain: int, commit: string, status: string}, attempts_left: int} $result */
        $result = \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);
        Assert::true($result['attempt']['accepted']);
        Assert::true($result['attempt']['gain'] > 0);
        Assert::same($result['attempt']['status'], 'diff-tested');
        Assert::same($result['attempts_left'], 1);
        Assert::string($this->git('log', '-1', '--format=%s'))->contains('opmin: LLM rewrite of App\Text::slug, -');
        Assert::same(\trim($this->git('status', '--porcelain')), '');
        $source = (string) \file_get_contents($this->dir . '/src/Text.php');
        Assert::same($source, \str_replace(<<<'PHP'
                    $lower = strtolower(trim($title));
                    $slug = preg_replace('/[^a-z0-9]+/', '-', $lower);

                    return trim((string) $slug, '-');
            PHP, "        return \\trim((string) \\preg_replace('/[^a-z0-9]+/', '-', \\strtolower(\\trim(\$title))), '-');", self::CODE));

        [$finish, $finishOut, $finishErr] = $this->opmin('llm:finish', '--format=json');
        Assert::same($finish, 0, $finishOut . $finishErr);
        /** @var array{ops_before: int, ops_after: int, patch: string, attempts: list<array<string, mixed>>} $report */
        $report = \json_decode($finishOut, true, flags: \JSON_THROW_ON_ERROR);
        Assert::true($report['ops_after'] < $report['ops_before']);
        Assert::count($report['attempts'], 1);
        Assert::string((string) \file_get_contents($this->dir . '/' . $report['patch']))->contains('-        $lower = strtolower(trim($title));');
        Assert::true(\is_file($this->dir . '/' . $targets['run'] . '/report.json'));

        [$after, , $afterErr] = $this->opmin('apply-candidate', 'App\Text::slug', $candidate);
        Assert::same($after, 2);
        Assert::string($afterErr)->contains('The session is finished');
    }

    public function finishTakesBackWhatTheFullTestRunRejects(): void
    {
        # The suite fails on the rewritten slug once `runs/broken` exists: the attempt passes, the final run does not.
        \file_put_contents($this->dir . '/check.php', <<<'PHP'
            <?php
            $rewritten = str_contains((string) file_get_contents(__DIR__ . '/src/Text.php'), '\preg_replace(');
            exit($rewritten && is_file(__DIR__ . '/runs/broken') ? 1 : 0);
            PHP);
        \file_put_contents($this->dir . '/opmin.yaml', "commands:\n  format: none\ntests:\n  runner: command\n  command: php check.php\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'tests');
        $this->opmin('llm:targets');
        $candidate = $this->candidate(<<<'PHP'
                public function slug(string $title): string
                {
                    return \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower(\trim($title))), '-');
                }
            PHP);
        [$code, $out, $err] = $this->opmin('apply-candidate', 'App\Text::slug', $candidate);
        Assert::same($code, 0, $out . $err);
        \touch($this->dir . '/runs/broken');

        [$finish, $finishOut, $finishErr] = $this->opmin('llm:finish', '--format=json');

        Assert::same($finish, 0, $finishOut . $finishErr);
        /** @var array{final_tests: bool, steps: list<array{accepted: list<mixed>, rejected: list<array{reason: string}>}>} $report */
        $report = \json_decode($finishOut, true, flags: \JSON_THROW_ON_ERROR);
        Assert::true($report['final_tests']);
        Assert::same($report['steps'][0]['accepted'], []);
        Assert::string($report['steps'][0]['rejected'][0]['reason'])->contains('the full test run fails');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
        Assert::string($this->git('log', '-1', '--format=%s'))->contains('opmin: take back');
        Assert::same(\trim($this->git('status', '--porcelain')), '');
    }

    public function behaviorChangeIsRejectedWithACounterexample(): void
    {
        $this->opmin('llm:targets');
        $candidate = $this->candidate(<<<'PHP'
                public function slug(string $title): string
                {
                    return \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \trim($title)), '-');
                }
            PHP);

        [$code, $out, $err] = $this->opmin('apply-candidate', 'App\Text::slug', $candidate, '--format=json');

        Assert::same($code, 1, $out . $err);
        /** @var array{attempt: array{accepted: bool, reason: string, counterexample: array{input: array<string, mixed>, test: string}}} $result */
        $result = \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);
        Assert::false($result['attempt']['accepted']);
        Assert::string($result['attempt']['reason'])->contains('differential test');
        Assert::true(\is_file($result['attempt']['counterexample']['test']));
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
        Assert::same(\trim($this->git('status', '--porcelain')), '');
        Assert::same(\trim($this->git('rev-list', '--count', 'HEAD')), '1');

        # The rejected attempt is in the context of the next one.
        $context = $this->json('llm:context', 'App\Text::slug', '--format=json');
        Assert::count($context['attempts'], 1);
        Assert::string($context['attempts'][0]['reason'])->contains('differential test');
        Assert::same($context['attempts_left'], 1);
    }

    public function candidateMustNotTouchAnythingElse(): void
    {
        $this->opmin('llm:targets');
        $extra = $this->candidate(<<<'PHP'
                public function slug(string $title): string
                {
                    return \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower(\trim($title))), '-');
                }

                public function helper(): void {}
            PHP);
        $signature = $this->candidate(<<<'PHP'
                public function slug(string $text): string
                {
                    return \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower(\trim($text))), '-');
                }
            PHP);

        [$extraCode, $extraOut] = $this->opmin('apply-candidate', 'App\Text::slug', $extra);
        [$signatureCode, $signatureOut] = $this->opmin('apply-candidate', 'App\Text::slug', $signature);

        Assert::same($extraCode, 1);
        Assert::string($extraOut)->contains('the candidate changes more than App\Text::slug: App\Text::helper');
        Assert::same($signatureCode, 1);
        Assert::string($signatureOut)->contains('changes the signature');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
    }

    public function nestedTernaryIsRejectedForReadability(): void
    {
        $this->opmin('llm:targets');
        $candidate = $this->candidate(<<<'PHP'
                public function kind(string $v): string
                {
                    return \in_array($v, ['a', 'b'], true) ? 'ab' : (\in_array($v, ['c', 'd'], true) ? 'cd' : 'other');
                }
            PHP);

        [$code, $out, $err] = $this->opmin('apply-candidate', 'App\Text::kind', $candidate);

        Assert::same($code, 1, $out . $err);
        Assert::string($out)->contains('nested_ternary');
        Assert::same(\file_get_contents($this->dir . '/src/Text.php'), self::CODE);
    }

    public function attemptsPerFunctionAreLimited(): void
    {
        $this->opmin('llm:targets');
        $candidate = $this->candidate(<<<'PHP'
                public function kind(string $v): string
                {
                    return 'other';
                }
            PHP);

        [$first] = $this->opmin('apply-candidate', 'App\Text::kind', $candidate);
        [$second] = $this->opmin('apply-candidate', 'App\Text::kind', $candidate);
        [$third, , $err] = $this->opmin('apply-candidate', 'App\Text::kind', $candidate);

        Assert::same([$first, $second, $third], [1, 1, 2]);
        Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains('No attempts left for `App\Text::kind`: 2 of llm.attempts_per_function = 2');
        Assert::count(\glob($this->dir . '/runs/*/candidates/*.php') ?: [], 2);
    }

    public function withoutASessionNothingRuns(): void
    {
        [$code, , $err] = $this->opmin('apply-candidate', 'App\Text::slug', '-');
        [$contextCode, , $contextErr] = $this->opmin('llm:context', 'App\Text::nope');

        Assert::same($code, 2);
        Assert::string($err)->contains('No LLM session');
        Assert::same($contextCode, 2);
        Assert::string($contextErr)->contains('No LLM session');
        $this->opmin('llm:targets');
        [$unknown, , $unknownErr] = $this->opmin('llm:context', 'App\Text::nope');
        Assert::same($unknown, 2);
        Assert::string($unknownErr)->contains('is not a target of the session');
    }

    /**
     * Writes a candidate into the run directory of the latest session (ignored by git, like the skill does).
     */
    private function candidate(string $source): string
    {
        $runs = \glob($this->dir . '/runs/*', \GLOB_ONLYDIR) ?: [];
        Assert::true($runs !== []);
        $file = \end($runs) . '/candidate-' . \bin2hex(\random_bytes(3)) . '.php';
        \file_put_contents($file, $source);

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string ...$args): array
    {
        [$code, $out, $err] = $this->opmin(...$args);
        Assert::same($code, 0, $out . $err);

        /** @var array<string, mixed> */
        return \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);
    }

    private function git(string ...$args): string
    {
        $out = [];
        \exec(
            'cd ' . \escapeshellarg($this->dir) . ' && git -c user.name=t -c user.email=t@t -c init.defaultBranch=main -c commit.gpgsign=false '
            . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1',
            $out,
            $code,
        );
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
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            \array_merge(\getenv(), [
                'OPMIN_PHP_BINARY' => TestPhp::path(),
                'GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t',
            ]),
        );
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $out, $err];
    }
}
