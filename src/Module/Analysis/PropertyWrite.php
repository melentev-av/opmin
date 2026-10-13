<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

/**
 * One write to a property found by {@see PropertyWrites}.
 *
 * @internal
 */
final readonly class PropertyWrite
{
    /**
     * @param non-empty-string $name The property, {@see PropertyWrites::ANY} for any.
     * @param bool $viaThis Written as `$this->name`.
     * @param bool $reference A reference to the property escapes (`&`, a by-reference argument,
     *        `unset()`): it may change later, from anywhere.
     */
    public function __construct(
        public string $name,
        public bool $viaThis,
        public bool $reference,
    ) {}
}
