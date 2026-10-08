<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

use Opmin\Module\Verification\Input;

/**
 * Smaller variants of an input, for reducing a counterexample to a minimal one.
 *
 * Works on any input — generated, mutated by the coverage search or replayed from the corpus — so
 * shrinking does not depend on how the input was produced.
 *
 * @internal
 */
interface InputShrinker
{
    /**
     * Strictly smaller variants, the most aggressive first; repeated shrinking always terminates.
     *
     * @return iterable<Input>
     */
    public function candidates(Input $input): iterable;
}
