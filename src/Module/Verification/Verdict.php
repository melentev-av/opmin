<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Opmin\Module\Analysis\Flag;

/**
 * Result of the differential test of one function.
 *
 * @internal
 */
final readonly class Verdict
{
    /**
     * @param non-empty-string $key
     * @param string $reason Why the function is not {@see VerdictStatus::Equivalent}; empty when it is.
     * @param float $coverage Branch coverage (%) of the original version by the inputs.
     * @param non-negative-int $inputs Inputs checked.
     * @param list<Flag> $flags
     */
    public function __construct(
        public string $key,
        public VerdictStatus $status,
        public string $reason = '',
        public float $coverage = 0.0,
        public int $inputs = 0,
        public int $probes = 0,
        public ?Counterexample $counterexample = null,
        public array $flags = [],
        public float $seconds = 0.0,
    ) {}

    public function accepted(): bool
    {
        return $this->status === VerdictStatus::Equivalent;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'coverage' => $this->coverage,
            'probes' => $this->probes,
            'inputs' => $this->inputs,
            'flags' => Flag::values($this->flags),
            'seconds' => \round($this->seconds, 3),
            'counterexample' => $this->counterexample?->toArray(),
        ];
    }
}
