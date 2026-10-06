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
                'ping' => ['ok' => true, 'php' => \PHP_VERSION, 'pid' => \getmypid()],
                'load' => Loader::load($request),
                'describe' => ['ok' => true, 'function' => Reflector::target((array) ($request['target'] ?? []))],
                'class' => ['ok' => true, 'class' => Reflector::class((string) ($request['name'] ?? ''))],
                'call' => Calls::call($request),
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
