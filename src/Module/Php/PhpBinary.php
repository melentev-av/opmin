<?php

declare(strict_types=1);

namespace Opmin\Module\Php;

/**
 * The production PHP (`php.binary`) as probed by {@see PhpBinaryProbe}.
 *
 * Everything that depends on the PHP version — compiling for opcode counts, the harness, the
 * project's tests — runs in a subprocess of this binary, never in the PHP running opmin.
 *
 * @internal
 */
final readonly class PhpBinary
{
    /**
     * @param non-empty-string $path Binary as configured (`php`, `/usr/bin/php8.1`).
     * @param non-empty-string $version `PHP_VERSION` of the binary.
     * @param int<80100, max> $versionId `PHP_VERSION_ID` of the binary.
     * @param list<non-empty-string> $loadArgs Arguments needed to load OPcache (empty when php.ini loads it).
     * @param list<non-empty-string> $zendExtensions Loaded Zend extensions, sorted (Xdebug, OPcache…).
     */
    public function __construct(
        public string $path,
        public string $version,
        public int $versionId,
        public array $loadArgs,
        public array $zendExtensions,
    ) {}

    /**
     * Command line that runs the binary with OPcache loaded and the given extra arguments.
     *
     * @param list<string> $args
     * @return non-empty-list<string>
     */
    public function command(array $args): array
    {
        return [$this->path, ...$this->loadArgs, ...$args];
    }
}
