<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Opmin\Module\Harness\HarnessException;
use Opmin\Module\Harness\Session;

/**
 * `--guard-perf` (brief, «Важное замечание по метрике»): the time of the original and the changed
 * function on the inputs of their differential test, in two workers compiled with OPcache as in
 * production. The versions alternate within every round and the median of the rounds' ratios is
 * taken, so a slower moment of the machine hits both and one noisy round does not decide.
 *
 * @internal
 */
final class Benchmark
{
    /** Inputs measured: spread over the ones the differential test checked. */
    private const INPUTS = 20;

    private const ROUNDS = 7;

    /** Time of one version on one input in one round. */
    private const TARGET_NS = 2_000_000;

    private const CALIBRATION = 10;

    /** Every measured call ran code compiled by OPcache. */
    private bool $opcache = true;

    /**
     * @param list<Input> $inputs Inputs both versions take (checked by the differential test).
     * @param array<string, mixed> $originalTarget
     * @param array<string, mixed> $changedTarget
     * @return array{original_ns: float, changed_ns: float, change_percent: float, inputs: int, rounds: int, opcache: bool}|null
     *         Mean time per call and the median change of the time (positive: slower); null when
     *         no input could be measured.
     * @throws HarnessException
     */
    public function measure(Session $original, Session $changed, array $originalTarget, array $changedTarget, array $inputs): ?array
    {
        $iterations = [];
        $chosen = [];
        foreach (self::spread($inputs, self::INPUTS) as $input) {
            $ns = $this->bench($original, $originalTarget, $input, self::CALIBRATION);
            if ($ns === null || $this->bench($changed, $changedTarget, $input, self::CALIBRATION) === null) {
                continue;
            }

            $perCall = \max(1, (int) ($ns / (float) self::CALIBRATION));
            $iterations[] = \max(5, \min(20_000, \intdiv(self::TARGET_NS, $perCall)));
            $chosen[] = $input;
        }

        if ($chosen === []) {
            return null;
        }

        $ratios = [];
        $totalOriginal = $totalChanged = 0.0;
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $timeOriginal = $timeChanged = 0.0;
            foreach ($chosen as $i => $input) {
                # Alternate who goes first: caches, frequency scaling.
                $first = $round % 2 === 0;
                $a = $first ? $this->bench($original, $originalTarget, $input, $iterations[$i]) : null;
                $b = $this->bench($changed, $changedTarget, $input, $iterations[$i]);
                $first or $a = $this->bench($original, $originalTarget, $input, $iterations[$i]);
                $timeOriginal += $a ?? 0.0;
                $timeChanged += $b ?? 0.0;
            }

            $timeOriginal > 0 and $ratios[] = $timeChanged / $timeOriginal;
            $totalOriginal += $timeOriginal;
            $totalChanged += $timeChanged;
        }

        if ($ratios === []) {
            return null;
        }

        \sort($ratios);
        $median = $ratios[\intdiv(\count($ratios), 2)];
        $calls = (float) (\array_sum($iterations) * self::ROUNDS);

        return [
            'original_ns' => \round($totalOriginal / $calls, 1),
            'changed_ns' => \round($totalChanged / $calls, 1),
            'change_percent' => \round(($median - 1.0) * 100.0, 1),
            'inputs' => \count($chosen),
            'rounds' => self::ROUNDS,
            'opcache' => $this->opcache,
        ];
    }

    /**
     * Up to `$count` inputs evenly spread over the list: boundary values come first, random ones later.
     *
     * @param list<Input> $inputs
     * @return list<Input>
     */
    private static function spread(array $inputs, int $count): array
    {
        $total = \count($inputs);
        if ($total <= $count) {
            return $inputs;
        }

        $result = [];
        for ($i = 0; $i < $count; ++$i) {
            $result[] = $inputs[\intdiv($i * $total, $count)];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $target
     * @param positive-int $iterations
     * @return float|null Nanoseconds; null when the input cannot be run.
     * @throws HarnessException
     */
    private function bench(Session $session, array $target, Input $input, int $iterations): ?float
    {
        $response = $session->query(['cmd' => 'bench', 'target' => $target, 'input' => $input->toArray(), 'iterations' => $iterations]);
        ($response['opcache'] ?? false) === true or $this->opcache = false;
        /** @var mixed $ns */
        $ns = $response['ns'] ?? null;

        return ($response['status'] ?? null) === 'done' && (\is_int($ns) || \is_float($ns)) ? (float) $ns : null;
    }
}
