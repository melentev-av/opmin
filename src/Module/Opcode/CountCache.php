<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Php\PhpBinary;

/**
 * Cache of per-file opcode counts in `cache.dir`.
 *
 * Key: the file's path and content hash, `PHP_VERSION` of `php.binary`, the optimizer hash, the opmin
 * version and {@see self::FORMAT}. Only successful counts are stored: a crash or a timeout may not
 * repeat. Unreadable or foreign entries are misses, never errors.
 *
 * @internal
 */
final class CountCache
{
    /** Bump when the stored data or the way it is computed changes. */
    private const FORMAT = 1;

    public function __construct(
        private readonly Path $dir,
        private readonly PhpBinary $php,
    ) {}

    /**
     * @param non-empty-string $file Path relative to the project root.
     * @return non-empty-string
     */
    public function key(string $file, string $content): string
    {
        return \hash('sha256', \serialize([
            self::FORMAT,
            $file,
            \hash('sha256', $content),
            $this->php->version,
            OptimizerSettings::hash($this->php),
            Info::version(),
        ]));
    }

    /**
     * @param non-empty-string $key
     * @return list<FunctionCount>|null
     */
    public function get(string $key): ?array
    {
        $raw = @\file_get_contents((string) $this->path($key));
        /** @var mixed $data */
        $data = $raw === false ? null : \json_decode($raw, true);
        if (!\is_array($data) || ($data['key'] ?? null) !== $key || !\is_array($data['functions'] ?? null)) {
            return null;
        }

        $result = [];
        try {
            /** @var array<non-empty-string, array<array-key, mixed>> $functions */
            $functions = $data['functions'];
            foreach ($functions as $function => $values) {
                $result[] = FunctionCount::fromArray($function, $values);
            }
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * @param non-empty-string $key
     * @param list<FunctionCount> $functions
     */
    public function set(string $key, array $functions): void
    {
        $data = ['key' => $key, 'functions' => []];
        foreach ($functions as $function) {
            $data['functions'][$function->key] = $function->toArray();
        }

        $path = $this->path($key);
        FS::mkdir((string) $path->parent());
        # Write and rename: a parallel or interrupted run never sees a half-written entry.
        $tmp = (string) $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        \file_put_contents($tmp, \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        \rename($tmp, (string) $path);
    }

    /**
     * @param non-empty-string $key
     */
    private function path(string $key): Path
    {
        return $this->dir->join('count', \substr($key, 0, 2), $key . '.json');
    }
}
