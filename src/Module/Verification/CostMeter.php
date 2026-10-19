<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Config\Schema\GuardCost;
use Opmin\Module\Harness\HarnessException;
use Opmin\Module\Harness\Session;
use Opmin\Module\Harness\WorkerOptions;
use Opmin\Module\Php\PhpBinary;
use Symfony\Component\Process\Process;

/**
 * `--guard-cost` (melentev-av/opmin#66, C3): CPU instructions per call of the original and the changed
 * function on inputs of their differential test, under callgrind, without the JIT and with the tracing
 * JIT. Instruction counts are deterministic, unlike time, so a few percent on a small function show.
 *
 * A worker per version and mode runs under `valgrind --tool=callgrind --dump-before=usleep`; every
 * input runs in a forked child ({@see https://github.com/melentev-av/opmin/blob/develop/docs/harness-protocol.md `cost`}):
 * calibrated against `bin/bench --cost` (bench/cost/harness.php), the differences match to a few
 * instructions, while inputs measured one after another in one process let the JIT traces of the
 * first input decide the cost of the next ones. The harness's own call path is subtracted.
 *
 * @internal
 */
final class CostMeter
{
    /** Calls before the measured runs: enough for the tracing JIT (hot functions after 127 calls). */
    private const int WARM = 300;

    private const int N1 = 100;
    private const int N2 = 300;

    /** A request under callgrind is slow: a margin of minutes, not seconds. */
    private const int TIMEOUT_MS = 900_000;

    private static ?bool $setarch = null;

    /**
     * @param non-empty-string $memoryLimit
     */
    public function __construct(
        private readonly PhpBinary $php,
        private readonly GuardCost $config,
        private readonly string $memoryLimit,
    ) {}

    /**
     * Whether `guard_cost.valgrind` runs: why not, or null.
     */
    public function unavailable(): ?string
    {
        $process = new Process([$this->config->valgrind, '--version'], timeout: 60);
        $process->run();

        return $process->isSuccessful()
            ? null
            : \sprintf('`%s --version` failed: guard_cost needs valgrind (Linux) or a wrapper that runs it in Docker.', $this->config->valgrind);
    }

    /**
     * @param array<string, mixed> $originalLoad The `load` request of the original.
     * @param array<string, mixed> $changedLoad
     * @param array<string, mixed> $originalTarget
     * @param array<string, mixed> $changedTarget
     * @param list<Input> $inputs Inputs both versions took in the differential test.
     * @return Cost|null Null when nothing could be measured.
     * @throws HarnessException
     */
    public function measure(array $originalLoad, array $changedLoad, array $originalTarget, array $changedTarget, array $inputs, Path $dir): ?Cost
    {
        $chosen = Benchmark::spread($inputs, $this->config->inputs);
        if ($chosen === []) {
            return null;
        }

        $modes = [];
        foreach ([Cost::OFF => false, Cost::TRACING => true] as $mode => $jit) {
            $before = $this->run($originalLoad, $originalTarget, $chosen, $jit, $dir->join("cost-{$mode}-original"));
            $after = $this->run($changedLoad, $changedTarget, $chosen, $jit, $dir->join("cost-{$mode}-changed"));
            $pairs = [];
            foreach ($before as $i => $cost) {
                isset($after[$i]) and $pairs[] = [$cost, $after[$i]];
            }

            if ($pairs === []) {
                return null;
            }

            $modes[$mode] = $pairs;
        }

        return new Cost($modes);
    }

    /**
     * The target's runs minus the noop's runs of one process: six dumps, one per `usleep(0)` of `cost`.
     * Any other number means the measured code slept itself (`\usleep()`): not measurable.
     */
    private static function perCall(Path $dir, int $pid): ?float
    {
        $dumps = [];
        $files = \glob("{$dir}/cg.{$pid}.*");
        foreach (\is_array($files) ? $files : [] as $file) {
            $lines = \file($file);
            foreach (\is_array($lines) ? $lines : [] as $line) {
                if (\str_starts_with($line, 'summary:')) {
                    $dumps[(int) \substr($file, (int) \strrpos($file, '.') + 1)] = (int) \substr($line, 8);
                    break;
                }
            }
        }

        \ksort($dumps);
        if (\array_keys($dumps) !== [1, 2, 3, 4, 5, 6]) {
            return null;
        }

        return (($dumps[3] - $dumps[2]) - ($dumps[6] - $dumps[5])) / (self::N2 - self::N1);
    }

    /**
     * Instructions per call of one version on every input it could be measured on, by input index.
     *
     * @param array<string, mixed> $load
     * @param array<string, mixed> $target
     * @param non-empty-list<Input> $inputs
     * @return array<int, float>
     * @throws HarnessException
     */
    private function run(array $load, array $target, array $inputs, bool $jit, Path $dir): array
    {
        FS::mkdir((string) $dir);
        $session = new Session($this->php, $load, new WorkerOptions(
            memoryLimit: $this->memoryLimit,
            timeoutMs: self::TIMEOUT_MS,
            loadTimeoutMs: self::TIMEOUT_MS,
            cwd: (string) $dir,
            opcache: true,
            jit: $jit,
            wrap: $this->wrap($dir),
            php: $this->config->php,
        ));
        $out = [];
        try {
            $session->query(['cmd' => 'ping']);
            foreach ($inputs as $i => $input) {
                $request = [
                    'cmd' => 'cost',
                    'target' => $target,
                    'input' => $input->toArray(),
                    'warm' => self::WARM,
                    'n1' => self::N1,
                    'n2' => self::N2,
                    'baseline' => true,
                ];
                $session->forks() and $request += ['isolate' => 'fork', 'timeout_ms' => self::TIMEOUT_MS];
                $response = $session->query($request);
                $pid = $response['pid'] ?? null;
                if (($response['status'] ?? null) === 'done' && \is_int($pid)) {
                    $cost = self::perCall($dir, $pid);
                    $cost === null or $out[$i] = $cost;
                }

                # Without pcntl, a process of its own per input: the next one starts without these traces.
                $session->forks() or $session->close();
            }
        } finally {
            $session->close();
            FS::remove($dir);
        }

        return $out;
    }

    /**
     * valgrind with its options; on Linux under `setarch -R` when it is there: without address
     * randomization, libc's string functions count the same from run to run.
     *
     * @return list<string>
     */
    private function wrap(Path $dir): array
    {
        $valgrind = [
            $this->config->valgrind,
            '--tool=callgrind',
            # The JIT writes code at run time.
            '--smc-check=all-non-file',
            '--dump-before=usleep',
            "--callgrind-out-file={$dir}/cg.%p",
        ];
        if ($this->config->valgrind !== 'valgrind') {
            return $valgrind;
        }

        if (self::$setarch === null) {
            $probe = new Process(['setarch', \php_uname('m'), '-R', 'true']);
            $probe->run();
            self::$setarch = $probe->isSuccessful();
        }

        return self::$setarch ? ['setarch', \php_uname('m'), '-R', ...$valgrind] : $valgrind;
    }
}
