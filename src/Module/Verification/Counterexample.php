<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Opmin\Module\Verification\Compare\Difference;

/**
 * An input on which the versions differ, already shrunk, with both results.
 *
 * @internal
 */
final readonly class Counterexample
{
    /**
     * @param array<string, mixed> $original Result of the original version.
     * @param array<string, mixed> $changed Result of the changed version.
     * @param bool $flaky The shrunk input did not reproduce the difference on a replay.
     */
    public function __construct(
        public Input $input,
        public Difference $difference,
        public array $original,
        public array $changed,
        public int $seed,
        public int $shrinkSteps = 0,
        public bool $flaky = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input->toArray(),
            'difference' => (string) $this->difference,
            'original' => $this->original,
            'changed' => $this->changed,
            'seed' => $this->seed,
            'shrink_steps' => $this->shrinkSteps,
            'flaky' => $this->flaky,
        ];
    }
}
