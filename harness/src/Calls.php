<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * The `call` command: builds the input, calls the function (several times in a row for `repeat`)
 * and describes everything observable — result or exception, output, errors, arguments after the
 * call, `$this`, the mock journal, changed globals and statics, coverage.
 *
 * @internal
 */
final class Calls
{
    /** @var array{calls: list<array<string, mixed>>, collector: ?ErrorCollector, level: int}|null The call in progress, for the shutdown handler. */
    public static ?array $pending = null;

    /**
     * @param array<array-key, mixed> $request
     * @return array<string, mixed>
     */
    public static function call(array $request): array
    {
        /** @var array<array-key, mixed> $target */
        $target = (array) ($request['target'] ?? []);
        /** @var array{args?: list<array<array-key, mixed>>, this?: array<array-key, mixed>|null, uses?: array<string, array<array-key, mixed>>, strict?: bool} $input */
        $input = (array) ($request['input'] ?? []);
        $throw = ($request['errors'] ?? 'record') === 'throw';
        $repeat = \max(1, (int) ($request['repeat'] ?? 1));
        [$file, $line] = Invoker::location($target);

        $describer = new Describer();
        $journal = new Journal($describer);
        $builder = new Builder($journal);
        Fakes::reset();
        Probe::reset();
        Mocks::begin($journal, $builder);
        $snapshot = Isolation::snapshot();
        try {
            try {
                # Build order: `this`, `uses`, arguments — a ref points to an object built before it.
                $receiver = isset($input['this']) ? $builder->build($input['this']) : null;
                $receiver === null || \is_object($receiver) or throw new \InvalidArgumentException('`this` must be an object');
                $uses = [];
                foreach ($input['uses'] ?? [] as $name => $use) {
                    /** @psalm-suppress MixedAssignment */
                    $uses[(string) $name] = $builder->build($use);
                }

                $args = $builder->buildList($input['args'] ?? []);
            } catch (\Throwable $e) {
                return ['ok' => true, 'status' => 'unbuildable', 'exception' => $describer->exception($e)];
            }

            /** @var object|null $receiver */
            self::$pending = ['calls' => [], 'collector' => null, 'level' => \ob_get_level() + 1];
            Coverage::start();
            $calls = [];
            $callArgs = $args;
            for ($i = 0; $i < $repeat; ++$i) {
                $i === 0 or $callArgs = $builder->buildList($input['args'] ?? []);
                $calls[] = self::once($target, $callArgs, $receiver, $uses, (bool) ($input['strict'] ?? false), new ErrorCollector($throw, $file, $line), $describer);
                self::$pending['calls'] = $calls;
            }

            $coverage = Coverage::stop();
            $changes = Isolation::changes($snapshot, $describer);
            $response = [
                'ok' => true,
                'status' => 'done',
                'calls' => $calls,
                'args' => $describer->describeList($callArgs),
                'this' => $receiver === null ? null : $describer->describe($receiver),
                'mocks' => $journal->entries(),
                'globals' => $changes['globals'],
                'statics' => $changes['statics'],
                'probes' => Probe::collect(),
            ];
            $coverage === null or $response['lines'] = $coverage;

            return $response;
        } finally {
            self::$pending = null;
            Mocks::end();
            Isolation::restore($snapshot);
        }
    }

    /**
     * The `bench` command: the time of `iterations` calls on one input, in nanoseconds. Arguments are
     * built before each call and the target is resolved once, so only the calls are measured; output,
     * warnings and exceptions are swallowed (their equivalence is proven already).
     *
     * @param array<array-key, mixed> $request
     * @return array<string, mixed>
     */
    public static function bench(array $request): array
    {
        /** @var array<array-key, mixed> $target */
        $target = (array) ($request['target'] ?? []);
        /** @var array{args?: list<array<array-key, mixed>>, this?: array<array-key, mixed>|null, uses?: array<string, array<array-key, mixed>>, strict?: bool} $input */
        $input = (array) ($request['input'] ?? []);
        $iterations = \max(1, (int) ($request['iterations'] ?? 100));

        $describer = new Describer();
        $journal = new Journal($describer);
        $builder = new Builder($journal);
        Fakes::reset();
        Mocks::begin($journal, $builder);
        $snapshot = Isolation::snapshot();
        $level = \ob_get_level();
        \set_error_handler(static fn(): bool => true);
        try {
            try {
                $receiver = isset($input['this']) ? $builder->build($input['this']) : null;
                $receiver === null || \is_object($receiver) or throw new \InvalidArgumentException('`this` must be an object');
                $uses = [];
                foreach ($input['uses'] ?? [] as $name => $use) {
                    /** @psalm-suppress MixedAssignment */
                    $uses[(string) $name] = $builder->build($use);
                }

                /** @var object|null $receiver */
                $call = Invoker::prepare($target, $receiver, $uses, (bool) ($input['strict'] ?? false));
            } catch (\Throwable $e) {
                return ['ok' => true, 'status' => 'unbuildable', 'exception' => $describer->exception($e)];
            }

            $ns = 0;
            \ob_start();
            for ($i = 0; $i < $iterations; ++$i) {
                $args = $builder->buildList($input['args'] ?? []);
                $start = \hrtime(true);
                try {
                    $call($args);
                } catch (\Throwable) {
                }

                $ns += \hrtime(true) - $start;
                \ob_get_length() > 1 << 20 and \ob_clean();
            }

            # Whether the timed code was compiled by OPcache with its optimizer, as in production.
            $status = \function_exists('opcache_get_status') ? @\opcache_get_status(false) : false;
            $opcache = \is_array($status) && ($status['opcache_enabled'] ?? false) === true
                && \function_exists('opcache_is_script_cached') && \opcache_is_script_cached((string) Invoker::location($target)[0]);

            return ['ok' => true, 'status' => 'done', 'ns' => $ns, 'iterations' => $iterations, 'opcache' => $opcache];
        } finally {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }

            \restore_error_handler();
            Mocks::end();
            Isolation::restore($snapshot);
        }
    }

    /**
     * Output of the buffers from the given level up, which are closed.
     */
    public static function output(int $level): string
    {
        $output = '';
        while (\ob_get_level() >= $level && \ob_get_level() > 0) {
            $output = (string) \ob_get_clean() . $output;
        }

        return $output;
    }

    /**
     * @param array<array-key, mixed> $target
     * @param list<mixed> $args
     * @param array<string, mixed> $uses
     * @return array<string, mixed>
     */
    private static function once(array $target, array &$args, ?object $receiver, array $uses, bool $strict, ErrorCollector $collector, Describer $describer): array
    {
        \assert(self::$pending !== null);
        self::$pending['collector'] = $collector;
        \ob_start();
        $level = \ob_get_level();
        \error_clear_last();
        $collector->start();
        try {
            /** @psalm-suppress MixedAssignment */
            $value = Invoker::invoke($target, $args, $receiver, $uses, $strict);
            # Described while errors and output are still captured: iterating a generator runs code.
            $result = ['status' => 'returned', 'value' => $describer->describe($value)];
        } catch (\Throwable $e) {
            $result = ['status' => 'threw', 'exception' => $describer->exception($e)];
        } finally {
            $collector->stop();
        }

        $result['output'] = Value::string(self::output($level));
        $result['errors'] = $collector->errors();
        self::$pending['collector'] = null;

        return $result;
    }
}
