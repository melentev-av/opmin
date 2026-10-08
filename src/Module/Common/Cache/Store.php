<?php

declare(strict_types=1);

namespace Opmin\Module\Common\Cache;

/**
 * Storage of cache entries (`cache.driver`): opaque strings by kind and key. A missing or unreadable
 * entry is a miss, never an error.
 *
 * @internal
 */
interface Store
{
    /**
     * @param non-empty-string $kind `count`, `refs`: entries of different kinds never collide.
     * @param non-empty-string $key Hex hash, at least two characters.
     */
    public function get(string $kind, string $key): ?string;

    /**
     * @param non-empty-string $kind
     * @param non-empty-string $key
     */
    public function set(string $kind, string $key, string $value): void;
}
