<?php

declare(strict_types=1);

namespace Opmin\Module\Harness;

/**
 * How harness workers are started.
 *
 * @internal
 */
final readonly class WorkerOptions
{
    /**
     * @param non-empty-string $memoryLimit `memory_limit` of the worker.
     * @param positive-int $timeoutMs Limit of one request (`call`); `load` gets {@see self::$loadTimeoutMs}.
     * @param positive-int $loadTimeoutMs Limit of starting the worker and of `load` (a framework boots).
     * @param non-empty-string|null $coverage Coverage driver to load (`xdebug` enables `xdebug.mode=coverage`).
     * @param non-empty-string|null $cwd Working directory of the worker: relative paths the called code touches
     *        resolve there, not in the project.
     * @param bool $opcache Compile with OPcache and the optimizer as in production, JIT off (time
     *        measurements); otherwise OPcache is off.
     */
    public function __construct(
        public string $memoryLimit = '256M',
        public int $timeoutMs = 1000,
        public int $loadTimeoutMs = 30000,
        public ?string $coverage = null,
        public ?string $cwd = null,
        public bool $opcache = false,
    ) {}
}
