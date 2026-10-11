<?php

declare(strict_types=1);

namespace Opmin\Module\Common;

/**
 * Where the time of a run goes: seconds and calls per level (Rector, PHPStan, the project's tests, the
 * differential tests…) and counters of what the run did. Levels of work done once per run start with
 * `once.`, sub-levels are joined with a dot (`diff_test.fuzz` is a part of `diff_test`).
 *
 * @internal
 */
final class Timings
{
    /** @var array<non-empty-string, array{seconds: float, calls: int}> */
    private array $levels = [];

    /** @var array<non-empty-string, int> */
    private array $counters = [];

    private readonly int $started;

    public function __construct()
    {
        $this->started = \hrtime(true);
    }

    /**
     * Runs the body and adds its time to the level, also when it throws.
     *
     * @template T
     * @param non-empty-string $level
     * @param \Closure(): T $body
     * @return T
     */
    public function measure(string $level, \Closure $body): mixed
    {
        $start = \hrtime(true);
        try {
            return $body();
        } finally {
            $this->add($level, (float) (\hrtime(true) - $start) / 1e9);
        }
    }

    /**
     * @param non-empty-string $level
     */
    public function add(string $level, float $seconds): void
    {
        $this->levels[$level] ??= ['seconds' => 0.0, 'calls' => 0];
        $this->levels[$level]['seconds'] += $seconds;
        ++$this->levels[$level]['calls'];
    }

    /**
     * @param non-empty-string $counter
     */
    public function count(string $counter, int $by = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    /**
     * Adds the levels and counters of another process ({@see self::toArray()}).
     *
     * @param array{levels?: array<non-empty-string, array{seconds: float, calls: int}>, counters?: array<non-empty-string, int>} $other
     */
    public function merge(array $other): void
    {
        foreach ($other['levels'] ?? [] as $level => $l) {
            $this->levels[$level] ??= ['seconds' => 0.0, 'calls' => 0];
            $this->levels[$level]['seconds'] += $l['seconds'];
            $this->levels[$level]['calls'] += $l['calls'];
        }

        foreach ($other['counters'] ?? [] as $counter => $value) {
            $this->count($counter, $value);
        }
    }

    /**
     * Seconds since the timings were created: the wall time of the run.
     */
    public function wall(): float
    {
        return (float) (\hrtime(true) - $this->started) / 1e9;
    }

    /**
     * @return array{wall_seconds: float, levels: array<non-empty-string, array{seconds: float, calls: int}>, counters: array<non-empty-string, int>}
     */
    public function toArray(): array
    {
        $levels = $this->levels;
        \ksort($levels);
        $counters = $this->counters;
        \ksort($counters);

        return [
            'wall_seconds' => \round($this->wall(), 3),
            'levels' => \array_map(static fn(array $l): array => ['seconds' => \round($l['seconds'], 3), 'calls' => $l['calls']], $levels),
            'counters' => $counters,
        ];
    }

    /**
     * One line per level, the slowest first, then the counters: for the verbose log.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $levels = $this->levels;
        \uasort($levels, static fn(array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
        $lines = [\sprintf('Time: %.1f s', $this->wall())];
        foreach ($levels as $level => $l) {
            $lines[] = \sprintf('  %-28s %9.2f s  %6d call(s)', $level, $l['seconds'], $l['calls']);
        }

        foreach ($this->counters as $counter => $value) {
            $lines[] = \sprintf('  %-28s %9d', $counter, $value);
        }

        return $lines;
    }
}
