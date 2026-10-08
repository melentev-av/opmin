<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

use Opmin\Module\Verification\Input;

/**
 * Branch coverage of the original version collected by the differential test, and the inputs that
 * opened new branches — the seeds of the coverage-guided search (phase 3).
 *
 * @internal
 */
final class Feedback
{
    private const POOL = 64;

    /** @var array<int, true> */
    private array $covered = [];

    /** @var list<Input> */
    private array $pool = [];

    /** @var array<int, true> Ids of all probes (branch probes 0…n-1, or executable lines). */
    private array $known = [];

    /** @var non-negative-int */
    private int $probes;

    /** @var non-negative-int Probes left out as dead code. */
    private int $dead = 0;

    /**
     * @param non-negative-int $probes Number of branch probes of the original function.
     */
    public function __construct(int $probes)
    {
        $this->probes = $probes;
        $this->known = $probes === 0 ? [] : \array_fill_keys(\range(0, $probes - 1), true);
    }

    /**
     * @return non-negative-int
     */
    public function probes(): int
    {
        return $this->probes;
    }

    /**
     * Line coverage: the probes are the executable lines, known after the first call.
     *
     * @param list<int> $ids
     * @param list<int> $dead Lines no input reaches: not counted.
     */
    public function resize(array $ids, array $dead = []): void
    {
        $this->known = \array_fill_keys($ids, true);
        $this->dead = 0;
        $this->exclude($dead);
    }

    /**
     * Leaves probes of dead code out: no input can hit them.
     *
     * @param list<int> $ids
     */
    public function exclude(array $ids): void
    {
        foreach ($ids as $id) {
            if (isset($this->known[$id])) {
                unset($this->known[$id]);
                ++$this->dead;
            }
        }

        $this->probes = \count($this->known);
    }

    /**
     * @return non-negative-int
     */
    public function dead(): int
    {
        return $this->dead;
    }

    /**
     * @param list<int> $hit Probes hit by the input.
     * @return bool Whether the input opened a new branch.
     */
    public function record(Input $input, array $hit): bool
    {
        $new = false;
        foreach ($hit as $probe) {
            if (!isset($this->covered[$probe])) {
                $this->covered[$probe] = true;
                $new = true;
            }
        }

        if ($new) {
            $this->pool[] = $input;
            \count($this->pool) > self::POOL and \array_shift($this->pool);
        }

        return $new;
    }

    /**
     * @return list<Input>
     */
    public function pool(): array
    {
        return $this->pool;
    }

    /**
     * @return non-negative-int
     */
    public function covered(): int
    {
        return \count(\array_intersect_key($this->covered, $this->known));
    }

    /**
     * Percentage of probes hit; 100 for a function without probes.
     */
    public function percent(): float
    {
        return $this->probes === 0 ? 100.0 : \round(100.0 * (float) $this->covered() / (float) $this->probes, 1);
    }

    public function complete(): bool
    {
        return $this->covered() >= $this->probes;
    }

    /**
     * @return list<int>
     */
    public function missed(): array
    {
        return \array_map(intval(...), \array_keys(\array_diff_key($this->known, $this->covered)));
    }
}
