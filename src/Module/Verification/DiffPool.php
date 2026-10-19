<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Internal\Path;
use Opmin\Module\Common\Cpu;
use Opmin\Module\Common\Memory;
use Opmin\Module\Common\Timings;
use Opmin\Module\Config\Schema\Verification as Config;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Progress\Event;
use Opmin\Module\Progress\Listener;
use Opmin\Module\Release\Installation;
use Symfony\Component\Process\Process;

/**
 * Runs the differential tests of one verification (brief, Модуль 2, level 3), `verification.jobs` at a
 * time: each test in a process of its own ({@see self::child()}), so that nothing of one test — the
 * state of the tester, the instrumented code — reaches another. With one job, or one test, they run here,
 * one after another.
 *
 * The verdicts come back in the order of the tasks. Guard-perf times the accepted ones afterwards, one
 * after another: timing next to other tests would measure their load.
 *
 * @internal
 */
final class DiffPool
{
    /** At most this many tests at a time with `verification.jobs: 0` (auto). */
    private const AUTO_MAX = 4;

    /** Marks the result in the output of a child: nothing else it may print is taken for it. */
    private const MARK = "\nOPMIN-DIFF-RESULT:";

    /** Inputs a child sends back for guard-perf. */
    private const PERF_INPUTS = 50;

    public function __construct(
        private readonly PhpBinary $php,
        private readonly Config $config,
        private readonly Path $workDir,
        private readonly ?Path $corpus,
        private readonly Timings $timings,
        private readonly Listener $progress,
    ) {}

    /**
     * Tests at a time: the configured number, or for 0 (auto) half of the CPU cores — a test keeps two
     * workers busy — limited by the memory both workers of each test may take, and by {@see self::AUTO_MAX}.
     *
     * @return positive-int
     */
    public static function jobs(int $configured, string $memoryLimit, ?int $cores = null, ?int $available = null): int
    {
        if ($configured > 0) {
            return $configured;
        }

        $jobs = \min(self::AUTO_MAX, \intdiv($cores ?? Cpu::count(), 2));
        $limit = Memory::bytes($memoryLimit) ?? 512 * 1024 ** 2;
        $available ??= Memory::available();
        $available === null or $jobs = \min($jobs, \intdiv($available, 2 * $limit));

        return \max(1, $jobs);
    }

    /**
     * The work of a child process (`OPMIN_INTERNAL=diff-test`): one task from its stdin, the output with
     * its verdict after {@see self::MARK}.
     */
    public static function child(string $input): string
    {
        \ini_set('display_errors', 'stderr');
        try {
            /** @var array{php: PhpBinary, config: Config, work: Path, corpus: ?Path, task: DiffTask} $payload */
            $payload = \unserialize($input);
            $timings = new Timings();
            $tester = new DiffTester($payload['php'], $payload['config'], $payload['work'], $payload['corpus'], timings: $timings);
            $verdict = $tester->verify($payload['task']);
            $result = [
                'verdict' => $verdict,
                'inputs' => \array_slice($tester->checkedInputs(), 0, self::PERF_INPUTS),
                'timings' => $timings->toArray(),
            ];
        } catch (\Throwable $e) {
            $result = ['error' => $e::class . ': ' . $e->getMessage()];
        }

        return self::MARK . \base64_encode(\serialize($result)) . "\n";
    }

    /**
     * @param list<DiffTask> $tasks
     * @param bool $measure Time both versions of every accepted function (guard-perf).
     * @param CostMeter|null $cost Count their instructions under callgrind (guard-cost).
     * @return list<Verdict> In the order of the tasks.
     */
    public function verify(array $tasks, bool $measure, ?CostMeter $cost = null): array
    {
        $jobs = self::jobs($this->config->jobs, $this->config->memoryLimit);
        if ($jobs === 1 || \count($tasks) <= 1) {
            $tester = $this->tester($measure, $this->timings, $cost);
            $verdicts = [];
            $tasks === [] or $this->progress->on(Event::diffTests(\count($tasks), 1));
            foreach ($tasks as $task) {
                $this->progress->on(Event::diffTest($task->key, \count($verdicts), \count($tasks)));
                $verdicts[] = $this->timings->measure('diff_test', static fn(): Verdict => $tester->verify($task));
                $this->progress->on(Event::diffDone(\count($verdicts), \count($tasks)));
            }

            return $verdicts;
        }

        $this->progress->on(Event::diffTests(\count($tasks), $jobs));
        $results = $this->timings->measure('diff_test.pool', fn(): array => $this->parallel($tasks, $jobs));
        $verdicts = [];
        $tester = $measure || $cost !== null ? $this->tester($measure, $this->timings, $cost) : null;
        foreach ($tasks as $i => $task) {
            [$verdict, $inputs] = $results[$i];
            $this->timings->add('diff_test', $verdict->seconds);
            $tester === null || !$verdict->accepted() or $verdict = $tester->perf($task, $verdict, $inputs);
            $verdicts[] = $verdict;
        }

        return $verdicts;
    }

    private function tester(bool $measure, Timings $timings, ?CostMeter $cost = null): DiffTester
    {
        return new DiffTester($this->php, $this->config, $this->workDir, $this->corpus, measure: $measure, timings: $timings, cost: $cost);
    }

    /**
     * @param list<DiffTask> $tasks
     * @param positive-int $jobs
     * @return array<int, array{Verdict, list<Input>}>
     */
    private function parallel(array $tasks, int $jobs): array
    {
        $installation = Installation::current();
        $pending = $tasks;
        /** @var array<int, Process> $running */
        $running = [];
        $results = [];
        $next = 0;
        try {
            while ($pending !== [] || $running !== []) {
                while ($pending !== [] && \count($running) < $jobs) {
                    $task = \array_shift($pending);
                    $this->progress->on(Event::diffTest($task->key, \count($results), \count($tasks)));
                    $process = new Process(
                        $installation->command(),
                        env: ['OPMIN_INTERNAL' => 'diff-test', 'OPMIN_NO_DELEGATE' => '1'],
                        input: \serialize([
                            'php' => $this->php,
                            'config' => $this->config,
                            'work' => $this->workDir,
                            'corpus' => $this->corpus,
                            'task' => $task,
                        ]),
                        timeout: null,
                    );
                    $process->start();
                    $running[$next++] = $process;
                }

                foreach ($running as $i => $process) {
                    if ($process->isRunning()) {
                        continue;
                    }

                    unset($running[$i]);
                    $results[$i] = $this->result($tasks[$i], $process);
                    $this->progress->on(Event::diffDone(\count($results), \count($tasks)));
                }

                $running === [] or \usleep(5_000);
            }
        } finally {
            foreach ($running as $process) {
                $process->stop(0);
            }
        }

        return $results;
    }

    /**
     * @return array{Verdict, list<Input>}
     */
    private function result(DiffTask $task, Process $process): array
    {
        $output = $process->getOutput();
        $at = \strrpos($output, self::MARK);
        /** @var array{verdict?: Verdict, inputs?: list<Input>, timings?: array{levels: array<non-empty-string, array{seconds: float, calls: int}>, counters: array<non-empty-string, int>}, error?: string}|false $result */
        $result = $at === false ? false : \unserialize((string) \base64_decode(\trim(\substr($output, $at + \strlen(self::MARK))), true));
        if (!\is_array($result) || isset($result['error']) || !isset($result['verdict'])) {
            throw new \RuntimeException(\sprintf(
                'The differential test of %s failed in its process (exit code %d): %s',
                $task->key,
                (int) $process->getExitCode(),
                \is_array($result) && isset($result['error']) ? $result['error'] : \trim(\substr($process->getErrorOutput() . "\n" . $output, -2000)),
            ));
        }

        isset($result['timings']) and $this->timings->merge($result['timings']);

        return [$result['verdict'], $result['inputs'] ?? []];
    }
}
