<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Dump;

/**
 * The OPcache dump of one file, or why it could not be compiled.
 *
 * @internal
 */
final readonly class FileDump
{
    /**
     * @param non-empty-string $file Absolute path.
     * @param string $dump Both phases of the dump; empty on error.
     * @param non-empty-string|null $error Compilation error (syntax above the runtime PHP, fatal error).
     */
    public function __construct(
        public string $file,
        public string $dump,
        public ?string $error = null,
    ) {}
}
