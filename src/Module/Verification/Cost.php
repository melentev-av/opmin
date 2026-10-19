<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

/**
 * Instructions per call of both versions of a function on every measured input, without the JIT and
 * with the tracing JIT (`--guard-cost`, {@see CostMeter}): callgrind, the harness's own call path
 * subtracted.
 *
 * @internal
 */
final readonly class Cost
{
    public const string OFF = 'off';
    public const string TRACING = 'tracing';

    /**
     * @param array<self::OFF|self::TRACING, non-empty-list<array{float, float}>> $modes Per input: [before, after].
     */
    public function __construct(
        public array $modes,
    ) {}

    /**
     * Why the changed version costs more than allowed, or null. A change costs more when, in either
     * mode, one input or the mean over the inputs grows by more than both `$percent` and
     * `$instructions` (melentev-av/opmin#66: one threshold alone flags noise on tiny or huge functions).
     *
     * @param non-negative-int $percent
     * @param non-negative-int $instructions
     */
    public function regression(int $percent, int $instructions): ?string
    {
        foreach ($this->modes as $mode => $pairs) {
            $beyond = \array_filter($pairs, static fn(array $p): bool => self::beyond($p[0], $p[1], $percent, $instructions));
            [$before, $after] = self::mean($pairs);
            if ($beyond === [] && !self::beyond($before, $after, $percent, $instructions)) {
                continue;
            }

            return \sprintf(
                'costs more %s: %+.0f instructions per call (%s), %d of %d inputs beyond guard_cost (%d%% and %d instructions)',
                $mode === self::OFF ? 'without the JIT' : 'with the tracing JIT',
                $after - $before,
                self::percent($before, $after),
                \count($beyond),
                \count($pairs),
                $percent,
                $instructions,
            );
        }

        return null;
    }

    /**
     * The mean of each mode, for the report.
     *
     * @return array<string, array{before: float, after: float, change_percent: float|null, inputs: int}>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->modes as $mode => $pairs) {
            [$before, $after] = self::mean($pairs);
            $out[$mode] = [
                'before' => \round($before, 1),
                'after' => \round($after, 1),
                'change_percent' => $before > 0 ? \round(($after - $before) * 100.0 / $before, 1) : null,
                'inputs' => \count($pairs),
            ];
        }

        return $out;
    }

    private static function beyond(float $before, float $after, int $percent, int $instructions): bool
    {
        $diff = $after - $before;

        return $diff > (float) $instructions && ($before <= 0.0 || $diff * 100.0 / $before > (float) $percent);
    }

    /**
     * @param non-empty-list<array{float, float}> $pairs
     * @return array{float, float}
     */
    private static function mean(array $pairs): array
    {
        $n = (float) \count($pairs);

        return [(float) \array_sum(\array_column($pairs, 0)) / $n, (float) \array_sum(\array_column($pairs, 1)) / $n];
    }

    private static function percent(float $before, float $after): string
    {
        return $before > 0 ? \sprintf('%+.1f%%', ($after - $before) * 100.0 / $before) : 'from nothing';
    }
}
