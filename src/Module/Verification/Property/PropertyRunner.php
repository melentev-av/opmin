<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

use Opmin\Module\Verification\Input;

/**
 * Runs a property "the check passes for every input" over generated inputs and shrinks a
 * counterexample. The seam between the verifier and the property-testing engine: the verifier
 * never touches the engine directly, so the engine can be replaced.
 *
 * @internal
 */
interface PropertyRunner
{
    /**
     * @param \Closure(Input): void $check Returns for a passing input, throws {@see Discard} for an
     *        input that says nothing, throws anything else for a failing one.
     */
    public function run(PropertySpec $spec, InputGenerator $generator, InputShrinker $shrinker, \Closure $check): PropertyOutcome;
}
