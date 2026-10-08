<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

/**
 * A parameter of the function under test (or a `use` variable of a closure).
 *
 * @internal
 */
final readonly class Parameter
{
    public function __construct(
        public string $name,
        public TypeSpec $type,
        public bool $optional = false,
        public bool $variadic = false,
        public bool $byRef = false,
        public bool $typed = false,
    ) {}
}
