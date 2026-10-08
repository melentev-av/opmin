<?php

declare(strict_types=1);

namespace Opmin\Module\Harness;

use Opmin\Module\Php\PhpBinary;

/**
 * A worker with one version of the code loaded, restarted when needed: after `exit()`, a fatal
 * error, a crash or a timeout (the process is gone), after {@see self::$maxRequests} calls (leaked
 * state, memory). In fresh mode (functions with `static` variables) every call starts from the
 * loaded state: in a forked child when the PHP has pcntl, otherwise in a new process.
 *
 * A worker that died during a call is a result of that call, not an error: `status` is `timeout` or
 * `crashed` (from the orchestrator) next to the harness's own `fatal` and `exited`.
 *
 * @internal
 */
final class Session
{
    private ?Worker $worker = null;
    private bool $loaded = false;

    /** @var non-negative-int */
    private int $calls = 0;

    /** @var non-negative-int */
    private int $starts = 0;

    /** A failure of sending, reported by {@see self::end()}. */
    private ?WorkerException $pending = null;

    /** The loaded worker can fork for fresh calls. */
    private bool $fork = false;

    /**
     * @param array<string, mixed> $load The `load` request of this version.
     * @param positive-int $maxRequests Calls before the worker is replaced.
     * @param bool $fresh A new process for every call.
     */
    public function __construct(
        private readonly PhpBinary $php,
        private readonly array $load,
        private readonly WorkerOptions $options = new WorkerOptions(),
        private readonly int $maxRequests = 500,
        private readonly bool $fresh = false,
    ) {}

    /**
     * Calls both sessions at the same time (each in its own process) and returns both responses.
     *
     * @param array<string, mixed> $request
     * @return array{array<string, mixed>, array<string, mixed>}
     * @throws HarnessException
     */
    public static function callBoth(self $a, self $b, array $request): array
    {
        $a->begin($request);
        $b->begin($request);

        return [$a->end(), $b->end()];
    }

    /**
     * Calls the function; see {@see self::begin()} and {@see self::end()} to call two sessions at once.
     *
     * @param array<string, mixed> $request A `call` request.
     * @return array<string, mixed>
     * @throws HarnessException
     */
    public function call(array $request): array
    {
        $this->begin($request);

        return $this->end();
    }

    /**
     * Sends a call without waiting for it.
     *
     * @param array<string, mixed> $request
     * @throws HarnessException
     */
    public function begin(array $request): void
    {
        ($this->fresh && !$this->fork || $this->calls >= $this->maxRequests) and $this->restart();
        $worker = $this->ready();
        ++$this->calls;
        $this->fresh && $this->fork and $request += ['isolate' => 'fork', 'timeout_ms' => $this->options->timeoutMs];
        try {
            $worker->send(['cmd' => 'call'] + $request);
        } catch (WorkerException $e) {
            $this->pending = $e;
            return;
        }

        $this->pending = null;
    }

    /**
     * The response to {@see self::begin()}.
     *
     * @return array<string, mixed>
     * @throws HarnessException
     */
    public function end(): array
    {
        $worker = $this->worker ?? throw new HarnessException('No call in progress.');
        try {
            $this->pending === null or throw $this->pending;
            # A forked call is timed by the worker itself; the margin covers the fork.
            $response = $worker->receive($this->fresh && $this->fork ? $this->options->timeoutMs + 2000 : null);
        } catch (WorkerException $e) {
            $this->restart();

            return [
                'ok' => true,
                'status' => $e->reason === WorkerException::TIMEOUT ? 'timeout' : 'crashed',
                'calls' => [],
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->pending = null;
        }

        ($response['ok'] ?? false) === true or throw new HarnessException('The harness refused the call: ' . self::error($response));
        # A forked child died, the worker did not.
        \in_array($response['status'] ?? null, ['fatal', 'exited'], true) && !($this->fresh && $this->fork) and $this->restart();

        return $response;
    }

    /**
     * Reflection of the function or a class through the loaded worker (`describe`, `class`).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     * @throws HarnessException
     */
    public function query(array $request): array
    {
        try {
            $response = $this->ready()->request($request, $this->options->loadTimeoutMs);
        } catch (WorkerException $e) {
            $this->restart();
            throw new HarnessException('The harness failed: ' . $e->getMessage(), previous: $e);
        }

        ($response['ok'] ?? false) === true or throw new HarnessException('The harness refused the request: ' . self::error($response));

        return $response;
    }

    /**
     * Times a worker was started (diagnostics, tests).
     *
     * @return non-negative-int
     */
    public function starts(): int
    {
        return $this->starts;
    }

    /**
     * Whether fresh calls run in forked children (pcntl in php.binary).
     */
    public function forks(): bool
    {
        return $this->fork;
    }

    public function close(): void
    {
        $this->worker?->stop();
        $this->worker = null;
        $this->loaded = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function error(array $response): string
    {
        $error = \is_string($response['error'] ?? null) ? (string) $response['error'] : '';

        return $error === '' ? 'no reason given' : $error;
    }

    private function restart(): void
    {
        $this->close();
    }

    /**
     * @throws HarnessException
     */
    private function ready(): Worker
    {
        if ($this->worker !== null && $this->loaded && $this->worker->isRunning()) {
            return $this->worker;
        }

        $this->close();
        $worker = new Worker($this->php, $this->options);
        try {
            $worker->start();
            ++$this->starts;
            $response = $worker->request(['cmd' => 'load'] + $this->load, $this->options->loadTimeoutMs);
        } catch (WorkerException $e) {
            $worker->stop();
            throw new HarnessException('The harness could not load the code: ' . $e->getMessage(), previous: $e);
        }

        if (($response['ok'] ?? false) !== true) {
            $worker->stop();
            throw new HarnessException('The harness could not load the code: ' . self::error($response));
        }

        $this->worker = $worker;
        $this->loaded = true;
        $this->calls = 0;
        $this->fork = ($response['fork'] ?? false) === true;

        return $worker;
    }
}
