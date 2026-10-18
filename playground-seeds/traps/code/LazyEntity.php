<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Trap: a lazy proxy (LazyEntityProxy) unsets the property and serves it from `__get()`, like
 * Doctrine proxies do; reads of `$this->name` in the entity reach the proxy's `__get()`. The second
 * read goes through `\strtoupper()`: a chain of two parts, so interpolation leaves it alone.
 */
class LazyEntity
{
    public string $name = 'real';

    public function greet(): string
    {
        return $this->name . ', ' . \strtoupper($this->name);
    }
}
