<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Compare;

/**
 * Where two call results differ: a path into the result and both sides in short form.
 *
 * @internal
 */
final readonly class Difference implements \Stringable
{
    /**
     * @param string $path `calls[0].value.items[2][1]`, `mocks`, `calls[0].errors`.
     */
    public function __construct(
        public string $path,
        public string $original,
        public string $changed,
    ) {}

    public function __toString(): string
    {
        return "{$this->path}: {$this->original} → {$this->changed}";
    }
}
