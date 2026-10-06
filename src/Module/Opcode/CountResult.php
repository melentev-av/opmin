<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode;

/**
 * Result of {@see OpcodeCounter::count()}.
 *
 * @internal
 */
final readonly class CountResult
{
    /**
     * @param list<FunctionCount> $functions By file, in dump order within a file.
     * @param array<non-empty-string, non-empty-string> $errors File (relative) => why it was not counted.
     * @param int<0, max> $files Files counted successfully.
     * @param int<0, max> $cached Of them, taken from the cache.
     */
    public function __construct(
        public array $functions,
        public array $errors,
        public int $files,
        public int $cached,
    ) {}
}
