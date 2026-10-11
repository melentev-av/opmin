<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property\Core;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Property\Discard;
use Opmin\Module\Verification\Property\Stop;
use Rasuvaeff\PropertyTesting\Runner\TrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;

/**
 * Runs the verifier's check for one engine trial.
 *
 * @internal
 */
final readonly class InputExecutor implements TrialExecutor
{
    /**
     * @param \Closure(Input): void $check
     */
    public function __construct(
        private \Closure $check,
    ) {}

    public function execute(array $arguments): TrialOutcome
    {
        /** @var array<array-key, mixed> $input */
        $input = $arguments[CorePropertyRunner::PARAMETER];
        try {
            ($this->check)(Input::fromArray($input));
        } catch (Discard) {
            return TrialOutcome::discarded();
        } catch (Stop) {
            # A skip, not a discard: it says nothing about the input, and the engine gives up after one
            # skip per requested run. Never a failure: there is no counterexample to shrink or store.
            return TrialOutcome::skipped();
        } catch (\Throwable $e) {
            return TrialOutcome::failed($e);
        }

        return TrialOutcome::passed();
    }
}
