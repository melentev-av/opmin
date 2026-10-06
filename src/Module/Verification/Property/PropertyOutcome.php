<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

use Opmin\Module\Verification\Input;

/**
 * Result of a property run: held, or falsified with a shrunk counterexample.
 *
 * @internal
 */
final readonly class PropertyOutcome
{
    /**
     * @param non-negative-int $checks Inputs checked (passed) before the end of the run.
     * @param Input|null $original The input that failed first; null when the property held.
     * @param Input|null $shrunk The minimal failing input found by shrinking.
     * @param \Throwable|null $failure What the check threw for {@see $shrunk}.
     * @param bool $flaky The shrunk input passed on a replay: the failure does not reproduce.
     * @param bool $gaveUp Too many inputs were discarded to check {@see PropertySpec::$runs}.
     */
    private function __construct(
        public int $checks,
        public int $seed,
        public ?Input $original = null,
        public ?Input $shrunk = null,
        public ?\Throwable $failure = null,
        public int $shrinkSteps = 0,
        public bool $flaky = false,
        public bool $gaveUp = false,
    ) {}

    /**
     * @param non-negative-int $checks
     */
    public static function held(int $checks, int $seed, bool $gaveUp = false): self
    {
        return new self($checks, $seed, gaveUp: $gaveUp);
    }

    /**
     * @param non-negative-int $checks
     */
    public static function falsified(
        int $checks,
        int $seed,
        Input $original,
        Input $shrunk,
        \Throwable $failure,
        int $shrinkSteps,
        bool $flaky,
    ): self {
        return new self($checks, $seed, $original, $shrunk, $failure, $shrinkSteps, $flaky);
    }

    /**
     * @psalm-assert-if-true !null $this->shrunk
     * @psalm-assert-if-true !null $this->original
     * @psalm-assert-if-true !null $this->failure
     */
    public function isFalsified(): bool
    {
        return $this->shrunk !== null;
    }
}
