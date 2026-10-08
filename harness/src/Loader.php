<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * The `load` command: the project's autoloader and the files of the version under test, each served
 * from its override under its real path, then the fakes of time and randomness.
 *
 * @internal
 */
final class Loader
{
    /**
     * @param array<array-key, mixed> $request
     * @return array<string, mixed>
     */
    public static function load(array $request): array
    {
        /** @var array<string, string> $overrides */
        $overrides = \is_array($request['overrides'] ?? null) ? $request['overrides'] : [];
        /** @var list<string> $files */
        $files = \is_array($request['files'] ?? null) ? \array_values((array) $request['files']) : [];
        $autoload = isset($request['autoload']) ? (string) $request['autoload'] : null;

        FileOverride::register($overrides);
        \ob_start();
        $level = \ob_get_level();
        try {
            $autoload === null or require_once $autoload;
            foreach ($files as $file) {
                require_once $file;
            }
        } finally {
            $output = Calls::output($level);
            FileOverride::unregister();
        }

        /** @var array{namespaces?: list<string>, time?: float|int, seed?: int} $fakes */
        $fakes = \is_array($request['fakes'] ?? null) ? $request['fakes'] : [];
        Fakes::install($fakes['namespaces'] ?? [], (float) ($fakes['time'] ?? 1700000000), (int) ($fakes['seed'] ?? 42));
        Coverage::configure(isset($request['coverage']) ? (string) $request['coverage'] : null, \array_keys($overrides) ?: $files);

        return ['ok' => true, 'php' => \PHP_VERSION, 'output' => Value::string($output), 'drivers' => Coverage::available(), 'fork' => Worker::canFork()];
    }
}
