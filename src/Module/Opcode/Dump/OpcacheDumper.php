<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Dump;

use Opmin\Module\Opcode\OptimizerSettings;
use Opmin\Module\Php\PhpBinary;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Compiles files with OPcache under `php.binary` and returns their dumps.
 *
 * A pool of `php.binary` processes, each compiling a batch of files (PHP starts in 20–50 ms, so a
 * process per file would dominate the run). Compiling a batch gives the same opcodes as compiling
 * the files one by one: OPcache compiles every script ignoring the other files (checked on PHP
 * 8.1–8.5, see the integration tests). Each file is framed with markers on stdout and stderr.
 *
 * `opcache_compile_file()` declares top-level functions in the process, so a file that redeclares a
 * function of an earlier file of the batch fails with a fatal error, and a fatal error leaves the
 * process in a broken state. Hence: the worker stops at the first failure, and a file that failed
 * after other files is retried first in a fresh process — only a failure there is the file's own.
 *
 * Processes run without a shell; the worker code is PHP 8.1 compatible.
 *
 * @internal
 */
final class OpcacheDumper
{
    public const BATCH_SIZE = 40;
    private const WORKER = <<<'PHP'
        foreach (array_slice($argv, 1) as $i => $file) {
            fwrite(STDOUT, "\n@@OPMIN-BEGIN {$i}\n");
            fwrite(STDERR, "\n@@OPMIN-BEGIN {$i}\n");
            try {
                $error = opcache_compile_file($file) ? null : 'OPcache could not compile the file';
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage() . ' on line ' . $e->getLine();
            }
            fwrite(STDERR, "\n@@OPMIN-END " . json_encode($error) . "\n");
            if ($error !== null) {
                exit(3);
            }
        }
        PHP;

    /** Number of `php.binary` processes started, for tests of the cache. */
    public int $processes = 0;

    /**
     * @param positive-int $workers Parallel processes.
     * @param positive-int $batchSize Files per process.
     */
    public function __construct(
        private readonly PhpBinary $php,
        private readonly int $workers,
        private readonly int $batchSize = self::BATCH_SIZE,
        private readonly float $timeout = 300.0,
    ) {}

    /**
     * @param list<non-empty-string> $files Absolute paths.
     * @param \Closure(FileDump): void $onFile Called once per file, in completion order.
     */
    public function dump(array $files, \Closure $onFile): void
    {
        $queue = \array_chunk($files, $this->batchSize);
        /** @var list<array{Process, list<non-empty-string>}> $running */
        $running = [];
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && \count($running) < $this->workers) {
                $batch = \array_shift($queue);
                $process = new Process(
                    $this->php->command([...OptimizerSettings::args(), '-r', self::WORKER, ...$batch]),
                    timeout: $this->timeout,
                );
                $process->start();
                ++$this->processes;
                $running[] = [$process, $batch];
            }

            $still = [];
            foreach ($running as [$process, $batch]) {
                if ($this->isRunning($process)) {
                    $still[] = [$process, $batch];
                    continue;
                }

                $rest = $this->collect($process, $batch, $onFile);
                $rest === [] or \array_unshift($queue, $rest);
            }

            $running = $still;
            $running === [] or \usleep(2000);
        }
    }

    private function isRunning(Process $process): bool
    {
        try {
            $process->checkTimeout();
        } catch (ProcessTimedOutException) {
            return false;
        }

        return $process->isRunning();
    }

    /**
     * @param list<non-empty-string> $batch
     * @param \Closure(FileDump): void $onFile
     * @return list<non-empty-string> Files to compile in a new process.
     */
    private function collect(Process $process, array $batch, \Closure $onFile): array
    {
        $stderr = $this->split($process->getErrorOutput());
        $stdout = $this->split($process->getOutput());
        foreach ($batch as $i => $file) {
            $chunk = $stderr[$i] ?? null;
            $end = $chunk === null ? false : \strrpos($chunk, "\n@@OPMIN-END ");
            if ($chunk !== null && $end !== false) {
                /** @var mixed $error */
                $error = \json_decode(\trim(\substr($chunk, $end + 13)));
                if ($error === null) {
                    $onFile(new FileDump($file, \substr($chunk, 0, $end)));
                    continue;
                }
            } else {
                $error = $process->isTerminated() && $process->getExitCode() !== null && $process->getExitCode() <= 128
                    ? 'PHP stopped while compiling the file'
                    : 'PHP crashed or timed out while compiling the file';
            }

            if ($i > 0) {
                return \array_slice($batch, $i);
            }

            $onFile(new FileDump($file, '', $this->message((string) $error, $stdout[$i] ?? $process->getOutput())));

            return \array_slice($batch, 1);
        }

        return [];
    }

    /**
     * @return non-empty-string
     */
    private function message(string $error, string $stdout): string
    {
        # A fatal error is printed to stdout (display_errors) and is more precise than our note.
        $printed = [];
        foreach (\explode("\n", $stdout) as $line) {
            $line = \trim($line);
            if ($line === '' || \str_starts_with($line, 'Stack trace:') || \str_starts_with($line, '#')
                || \str_contains($line, 'could not compile file')
            ) {
                continue;
            }

            $printed[] = \preg_replace('/^PHP\s+/', '', $line);
        }

        $message = $printed !== [] && !\str_contains($error, ': ') ? \implode(' ', $printed) : $error;

        return $message === '' ? 'Compilation failed' : $message;
    }

    /**
     * @return array<int, string> Output of each file, by its index in the batch.
     */
    private function split(string $output): array
    {
        $parts = \preg_split('/\n@@OPMIN-BEGIN (\d+)\n/', $output, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $result = [];
        for ($i = 1, $n = \count($parts); $i + 1 < $n; $i += 2) {
            $result[(int) $parts[$i]] = $parts[$i + 1];
        }

        return $result;
    }
}
