<?php

declare(strict_types=1);

namespace Opmin\Module\Common;

use Symfony\Component\Process\Process;

/**
 * Memory of the machine, for the size of process pools.
 *
 * @internal
 */
final class Memory
{
    /**
     * Bytes the machine can give to new processes; null when unknown (the pool is not limited by it).
     */
    public static function available(): ?int
    {
        /** @var int|false|null $available */
        static $available = false;

        return $available === false ? $available = self::detect() : $available;
    }

    /**
     * Bytes of a `memory_limit` value; null for `-1` (no limit) or a value that cannot be read.
     */
    public static function bytes(string $limit): ?int
    {
        if (\preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $limit, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * match (\strtolower($m[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
    }

    private static function detect(): ?int
    {
        if (\is_readable('/proc/meminfo')) {
            $info = (string) @\file_get_contents('/proc/meminfo');

            return \preg_match('/^MemAvailable:\s+(\d+) kB/m', $info, $m) === 1 ? (int) $m[1] * 1024 : null;
        }

        if (\PHP_OS_FAMILY === 'Darwin') {
            # macOS reports no "available" memory that means the same: half of the total is a safe guess.
            $process = new Process(['sysctl', '-n', 'hw.memsize'], timeout: 5);
            $process->run();
            $total = (int) \trim($process->getOutput());

            return $process->isSuccessful() && $total > 0 ? \intdiv($total, 2) : null;
        }

        return null;
    }
}
