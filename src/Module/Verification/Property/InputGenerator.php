<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

use Opmin\Module\Verification\Input;

/**
 * Produces inputs of a differential test. A generator may keep state between calls (a queue of
 * boundary values, a pool of inputs that opened new branches): a fresh generator per run.
 *
 * @internal
 */
interface InputGenerator
{
    public function generate(RandomSource $random): Input;
}
