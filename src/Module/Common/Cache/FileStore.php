<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Cache;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;

/**
 * `cache.driver: files`: an entry per file, `<dir>/<kind>/<first two characters of the key>/<key>.json`.
 * Kept between runs and shared by parallel ones.
 *
 * @internal
 */
final class FileStore implements Store
{
    public function __construct(
        private readonly Path $dir,
    ) {}

    public function get(string $kind, string $key): ?string
    {
        $raw = @\file_get_contents((string) $this->path($kind, $key));

        return $raw === false ? null : $raw;
    }

    public function set(string $kind, string $key, string $value): void
    {
        $path = $this->path($kind, $key);
        FS::mkdir((string) $path->parent());
        # Write and rename: a parallel or interrupted run never sees a half-written entry.
        $tmp = (string) $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        \file_put_contents($tmp, $value);
        \rename($tmp, (string) $path);
    }

    /**
     * @param non-empty-string $kind
     * @param non-empty-string $key
     */
    private function path(string $kind, string $key): Path
    {
        return $this->dir->join($kind, \substr($key, 0, 2), $key . '.json');
    }
}
