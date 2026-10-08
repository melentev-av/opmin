<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Cache;

/**
 * `cache.driver: memory`: entries live as long as the process, nothing is written to disk.
 *
 * @internal
 */
final class MemoryStore implements Store
{
    /** @var array<non-empty-string, array<non-empty-string, string>> */
    private array $entries = [];

    public function get(string $kind, string $key): ?string
    {
        return $this->entries[$kind][$key] ?? null;
    }

    public function set(string $kind, string $key, string $value): void
    {
        $this->entries[$kind][$key] = $value;
    }
}
