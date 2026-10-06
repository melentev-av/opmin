<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * The harness worker: a long-lived process that serves JSON Lines requests of the orchestrator.
 *
 * Responses are written to a duplicate of stdout, each line prefixed with the session token: the
 * analyzed code may write to stdout too, and such lines are not responses. A fatal error or `exit()`
 * during a call still produces a response from the shutdown handler, then the process ends.
 *
 * @internal
 */
final class Worker
{
    private const FATAL = [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR, \E_RECOVERABLE_ERROR];

    /** @var resource */
    private $out;

    private string $reserve = '';
    private bool $busy = false;
    private string $command = '';

    private function __construct(
        private string $token,
    ) {
        $out = \fopen('php://stdout', 'wb');
        $out === false and throw new \RuntimeException('Cannot open stdout');
        $this->out = $out;
    }

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $token = $argv[1] ?? '';
        if ($token === '') {
            \fwrite(\STDERR, "Usage: php worker.php <token>\n");
            return 2;
        }

        (new self($token))->run();

        return 0;
    }

    /**
     * Whether a call can run in a forked child: state it changes (`static` variables) dies with it.
     */
    public static function canFork(): bool
    {
        return \function_exists('pcntl_fork') && \function_exists('pcntl_waitpid') && \function_exists('posix_kill')
            && \function_exists('stream_socket_pair');
    }

    /**
     * @internal Called by PHP at the end of the process.
     */
    public function shutdown(): void
    {
        if (!$this->busy) {
            return;
        }

        $this->reserve = '';
        $error = \error_get_last();
        $fatal = $error !== null && \in_array($error['type'], self::FATAL, true);
        $status = $fatal ? 'fatal' : 'exited';
        $message = $fatal ? $error['message'] : null;
        if ($this->command !== 'call' || Calls::$pending === null) {
            $this->send(['ok' => false, 'status' => $status, 'error' => $message ?? 'exit() during ' . $this->command]);
            return;
        }

        /** @var array{calls: list<array<string, mixed>>, collector: ?ErrorCollector, level: int} $pending */
        $pending = Calls::$pending;
        $collector = $pending['collector'];
        $current = ['status' => $status, 'message' => $message];
        $current['output'] = Value::string(Calls::output($pending['level']));
        $current['errors'] = $collector === null ? [] : $collector->errors();
        $calls = $pending['calls'];
        $calls[] = $current;
        $this->send(['ok' => true, 'status' => $status, 'calls' => $calls]);
    }

    /**
     * Runs the call in a child process and relays its response; the worker itself never runs the
     * function, so the next call starts from the same state. The child's `exit()` and fatal errors
     * are answered by its own shutdown handler; a hang is killed after `timeout_ms`.
     *
     * @param array<array-key, mixed> $request
     * @return array<string, mixed>
     */
    private function forked(array $request): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $pid = $pair === false ? -1 : \pcntl_fork();
        if ($pair === false || $pid === -1) {
            /** @var array<string, mixed> */
            return Calls::call($request);
        }

        if ($pid === 0) {
            \fclose($pair[0]);
            $this->out = $pair[1];
            $this->send(Calls::call($request));
            $this->busy = false;
            exit(0);
        }

        \fclose($pair[1]);
        $timeout = \max(1, (int) ($request['timeout_ms'] ?? 1000));
        $line = $this->readChild($pair[0], $timeout);
        \fclose($pair[0]);
        if ($line === null) {
            \posix_kill($pid, 9);
        }

        \pcntl_waitpid($pid, $status);
        if ($line === null || !\str_starts_with($line, $this->token . ' ')) {
            return ['ok' => true, 'status' => $line === null ? 'timeout' : 'crashed', 'calls' => []];
        }

        /** @var array<string, mixed>|null $response */
        $response = \json_decode(\substr((string) $line, \strlen($this->token) + 1), true);

        return \is_array($response) ? $response : ['ok' => true, 'status' => 'crashed', 'calls' => []];
    }

    /**
     * The response line of the child, '' when it died without one, null on timeout.
     *
     * @param resource $pipe
     */
    private function readChild($pipe, int $timeoutMs): ?string
    {
        $deadline = \hrtime(true) + $timeoutMs * 1_000_000;
        $buffer = '';
        \stream_set_blocking($pipe, false);
        while (!\str_contains($buffer, "\n")) {
            $left = $deadline - \hrtime(true);
            if ($left <= 0) {
                return null;
            }

            $read = [$pipe];
            $write = $except = null;
            if (@\stream_select($read, $write, $except, \intdiv($left, 1_000_000_000), \intdiv($left % 1_000_000_000, 1000)) === false) {
                continue;
            }

            $chunk = (string) \fread($pipe, 65536);
            if ($chunk === '' && \feof($pipe)) {
                return $buffer;
            }

            $buffer .= $chunk;
        }

        return \substr($buffer, 0, (int) \strpos($buffer, "\n"));
    }

    private function run(): void
    {
        \ini_set('display_errors', '0');
        \ini_set('log_errors', '0');
        \ini_set('html_errors', '0');
        \error_reporting(\E_ALL);
        \set_time_limit(0);
        \register_shutdown_function([$this, 'shutdown']);
        # Memory for the response after an out-of-memory fatal.
        $this->reserve = \str_repeat(' ', 1 << 20);
        $this->send(['ok' => true, 'ready' => true, 'php' => \PHP_VERSION, 'pid' => \getmypid()]);

        while (($line = \fgets(\STDIN)) !== false) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }

            /** @var mixed $request */
            $request = \json_decode($line, true);
            if (!\is_array($request)) {
                $this->send(['ok' => false, 'error' => 'Bad request: ' . \json_last_error_msg()]);
                continue;
            }

            $this->command = (string) ($request['cmd'] ?? '');
            $this->busy = true;
            $response = $this->handle($request);
            $this->busy = false;
            $this->send($response);
            if ($this->command === 'shutdown') {
                return;
            }
        }
    }

    /**
     * @param array<array-key, mixed> $request
     * @return array<string, mixed>
     */
    private function handle(array $request): array
    {
        try {
            return match ($this->command) {
                'ping' => ['ok' => true, 'php' => \PHP_VERSION, 'pid' => \getmypid(), 'fork' => self::canFork()],
                'load' => Loader::load($request),
                'describe' => ['ok' => true, 'function' => Reflector::target((array) ($request['target'] ?? []))],
                'class' => ['ok' => true, 'class' => Reflector::class((string) ($request['name'] ?? ''))],
                'call' => ($request['isolate'] ?? null) === 'fork' && self::canFork() ? $this->forked($request) : Calls::call($request),
                'shutdown' => ['ok' => true],
                default => ['ok' => false, 'error' => "Unknown command `{$this->command}`"],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => \get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function send(array $response): void
    {
        $json = \json_encode($response, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        \fwrite($this->out, $this->token . ' ' . ($json === false ? '{"ok":false,"error":"Response cannot be encoded"}' : $json) . "\n");
        \fflush($this->out);
    }
}
