<?php

declare(strict_types=1);

namespace Opmin\Module\Common;

use Symfony\Component\Process\Process;

/**
 * Number of CPU cores, the default size of process pools.
 *
 * @internal
 */
final class Cpu
{
    /**
     * @return positive-int
     */
    public static function count(): int
    {
        /** @var positive-int|null $count */
        static $count = null;

        return $count ??= self::detect();
    }

    /**
     * @return positive-int
     */
    private static function detect(): int
    {
        $env = \getenv('NUMBER_OF_PROCESSORS');
        $candidates = [
            \is_string($env) ? $env : '',
            \is_readable('/proc/cpuinfo') ? (string) \preg_match_all('/^processor\s*:/m', (string) \file_get_contents('/proc/cpuinfo')) : '',
            \PHP_OS_FAMILY === 'Darwin' || \PHP_OS_FAMILY === 'BSD' ? self::sysctl() : '',
        ];
        foreach ($candidates as $candidate) {
            $n = (int) \trim($candidate);
            if ($n > 0) {
                return $n;
            }
        }

        return 4;
    }

    private static function sysctl(): string
    {
        try {
            $process = new Process(['sysctl', '-n', 'hw.ncpu']);
            $process->run();

            return $process->getOutput();
        } catch (\Throwable) {
            return '';
        }
    }
}
