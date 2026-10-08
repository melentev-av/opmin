<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

/**
 * Reflection of a class in the version under test (the harness `class` command), for building
 * objects and mocks.
 *
 * @internal
 */
interface ClassInfoProvider
{
    /**
     * @return array<string, mixed>|null Null when the class does not exist.
     */
    public function info(string $class): ?array;
}
