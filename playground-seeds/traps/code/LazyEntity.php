<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Trap: a lazy proxy (LazyEntityProxy) unsets the property and serves it from `__get()`, like
 * Doctrine proxies do; reads of `$this->name` in the entity reach the proxy's `__get()`.
 */
class LazyEntity
{
    public string $name = 'real';

    public function greet(): string
    {
        return $this->name . ', ' . $this->name;
    }
}
