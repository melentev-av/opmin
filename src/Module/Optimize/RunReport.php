<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

/**
 * Result of an optimization run: opcodes before and after, every step, what was not checked.
 *
 * @internal
 */
final class RunReport
{
    /** @var list<StepReport> */
    public array $steps = [];

    /** @var list<string> */
    public array $notes = [];

    public int $opsAfter = 0;

    /** Result of the final full run of the project's tests; null when there is no runner. */
    public ?bool $finalTests = null;

    public ?string $patch = null;

    public function __construct(
        public readonly int $opsBefore,
        public readonly string $runDir,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ops_before' => $this->opsBefore,
            'ops_after' => $this->opsAfter,
            'final_tests' => $this->finalTests,
            'patch' => $this->patch,
            'notes' => $this->notes,
            'steps' => \array_map(static fn(StepReport $s): array => $s->toArray(), $this->steps),
        ];
    }
}
