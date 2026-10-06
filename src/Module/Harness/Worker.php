<?php

declare(strict_types=1);

namespace Opmin\Module\Harness;

use Opmin\Module\Php\PhpBinary;

/**
 * One harness worker process under `php.binary`: JSON Lines over stdin/stdout (`docs/harness-protocol.md`).
 *
 * A request that does not get an answer in time kills the process ({@see WorkerException::TIMEOUT});
 * a process that dies without an answer is a {@see WorkerException::CRASH}. `exit()` and fatal
 * errors are answered by the harness itself and are ordinary responses. Either way the worker is
 * not usable after the process ended: start a new one.
 *
 * @internal
 */
final class Worker
{
    /** Keep at most this much of stderr for diagnostics. */
    private const STDERR_LIMIT = 8192;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';
    private string $stderr = '';
    private string $token = '';

    /** @var non-negative-int */
    private int $requests = 0;

    public function __construct(
        private readonly PhpBinary $php,
        private readonly WorkerOptions $options = new WorkerOptions(),
    ) {}

    /**
     * Starts the process and waits for its `ready` line.
     *
     * @return array<string, mixed> The ready message.
     * @throws WorkerException
     */
    public function start(): array
    {
        $this->kill();
        $this->token = \bin2hex(\random_bytes(8));
        $this->buffer = $this->stderr = '';
        $this->requests = 0;
        $command = [
            $this->php->path,
            '-d', 'opcache.enable_cli=0',
            '-d', 'memory_limit=' . $this->options->memoryLimit,
            '-d', 'display_errors=0',
            '-d', 'log_errors=0',
            '-d', 'xdebug.mode=' . ($this->options->coverage === 'xdebug' ? 'coverage' : 'off'),
            (string) HarnessFiles::worker(),
            $this->token,
        ];
        $process = @\proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        \is_resource($process) or throw new WorkerException(WorkerException::START, "Cannot start the harness with php.binary `{$this->php->path}`.");
        $this->process = $process;
        /** @var array<int, resource> $pipes */
        $this->pipes = $pipes;
        \stream_set_blocking($this->pipes[1], false);
        \stream_set_blocking($this->pipes[2], false);

        $ready = $this->read($this->options->loadTimeoutMs);
        ($ready['ready'] ?? false) === true or throw new WorkerException(WorkerException::START, 'The harness did not report ready.', $this->stderr);

        return $ready;
    }

    public function isRunning(): bool
    {
        if (!\is_resource($this->process)) {
            return false;
        }

        return \proc_get_status($this->process)['running'];
    }

    /**
     * Requests answered since the start.
     *
     * @return non-negative-int
     */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Sends a request and waits for the response.
     *
     * @param array<string, mixed> $request
     * @param positive-int|null $timeoutMs Default: the call timeout ({@see WorkerOptions::$timeoutMs}).
     * @return array<string, mixed>
     * @throws WorkerException
     */
    public function request(array $request, ?int $timeoutMs = null): array
    {
        $this->isRunning() or throw new WorkerException(WorkerException::CRASH, 'The harness is not running.', $this->stderr);
        $line = \json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) . "\n";
        $written = @\fwrite($this->pipes[0], $line);
        if ($written !== \strlen($line)) {
            $this->kill();
            throw new WorkerException(WorkerException::CRASH, 'The harness closed its input.', $this->stderr);
        }

        \fflush($this->pipes[0]);
        $response = $this->read($timeoutMs ?? $this->options->timeoutMs);
        ++$this->requests;

        return $response;
    }

    /**
     * Asks the worker to exit; kills it if it does not.
     */
    public function stop(): void
    {
        if ($this->isRunning()) {
            try {
                $this->request(['cmd' => 'shutdown'], 1000);
            } catch (WorkerException) {
                # Killed below.
            }
        }

        $this->kill();
    }

    /**
     * Diagnostics: what the worker wrote to stderr (truncated).
     */
    public function stderr(): string
    {
        return $this->stderr;
    }

    public function __destruct()
    {
        $this->kill();
    }

    /**
     * @param positive-int $timeoutMs
     * @return array<string, mixed>
     */
    private function read(int $timeoutMs): array
    {
        $deadline = \hrtime(true) + $timeoutMs * 1_000_000;
        $prefix = $this->token . ' ';
        while (true) {
            while (($end = \strpos($this->buffer, "\n")) !== false) {
                $line = \substr($this->buffer, 0, $end);
                $this->buffer = \substr($this->buffer, $end + 1);
                if (!\str_starts_with($line, $prefix)) {
                    # Output of the analyzed code that escaped capture.
                    continue;
                }

                /** @var mixed $response */
                $response = \json_decode(\substr($line, \strlen($prefix)), true);
                \is_array($response) or throw new WorkerException(WorkerException::PROTOCOL, 'The harness sent an invalid response: ' . \substr($line, 0, 200), $this->stderr);

                /** @var array<string, mixed> $response */
                return $response;
            }

            $left = $deadline - \hrtime(true);
            if ($left <= 0) {
                $this->kill();
                throw new WorkerException(WorkerException::TIMEOUT, "The harness did not answer in {$timeoutMs} ms.", $this->stderr);
            }

            $read = [$this->pipes[1], $this->pipes[2]];
            $write = $except = null;
            $ready = @\stream_select($read, $write, $except, \intdiv($left, 1_000_000_000), \intdiv($left % 1_000_000_000, 1000));
            if ($ready === false) {
                continue;
            }

            $eof = false;
            foreach ($read as $pipe) {
                $chunk = (string) \fread($pipe, 65536);
                if ($pipe === $this->pipes[2]) {
                    $this->stderr = \substr($this->stderr . $chunk, -self::STDERR_LIMIT);
                    continue;
                }

                $this->buffer .= $chunk;
                $chunk === '' && \feof($pipe) and $eof = true;
            }

            if ($eof && !\str_contains($this->buffer, "\n")) {
                $status = \is_resource($this->process) ? \proc_get_status($this->process) : null;
                $this->kill();
                throw new WorkerException(
                    WorkerException::CRASH,
                    'The harness ended without an answer' . ($status === null ? '.' : " (exit code {$status['exitcode']})."),
                    $this->stderr,
                );
            }
        }
    }

    private function kill(): void
    {
        foreach ($this->pipes as $pipe) {
            \is_resource($pipe) and \fclose($pipe);
        }

        $this->pipes = [];
        if (\is_resource($this->process)) {
            # SIGTERM first: a wrapper (`docker run --init`) forwards it, SIGKILL would orphan the container.
            if (\proc_get_status($this->process)['running']) {
                \proc_terminate($this->process, 15);
                $deadline = \hrtime(true) + 500_000_000;
                while (\proc_get_status($this->process)['running'] && \hrtime(true) < $deadline) {
                    \usleep(5000);
                }

                \proc_get_status($this->process)['running'] and \proc_terminate($this->process, 9);
            }

            \proc_close($this->process);
        }

        $this->process = null;
    }
}
