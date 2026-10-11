<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

/**
 * The time of a run for the renderers: the wall clock and the seconds since the run started.
 *
 * @internal
 */
final class Clock
{
    /** @var \Closure(): float */
    private readonly \Closure $now;

    private readonly float $start;

    /**
     * @param \Closure(): float|null $now Unix time in seconds; the system clock by default.
     */
    public function __construct(?\Closure $now = null)
    {
        $this->now = $now ?? static fn(): float => \microtime(true);
        $this->start = ($this->now)();
    }

    /**
     * `42s`, `3m07s`, `1h02m`.
     */
    public static function format(float $seconds): string
    {
        $seconds = (int) \max(0, \floor($seconds));

        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => \sprintf('%dm%02ds', \intdiv($seconds, 60), $seconds % 60),
            default => \sprintf('%dh%02dm', \intdiv($seconds, 3600), \intdiv($seconds % 3600, 60)),
        };
    }

    public function now(): float
    {
        return ($this->now)();
    }

    /**
     * Seconds since the run started.
     */
    public function elapsed(): float
    {
        return $this->now() - $this->start;
    }

    /**
     * The wall clock, `14:02:11`.
     */
    public function time(): string
    {
        return \date('H:i:s', (int) $this->now());
    }
}
