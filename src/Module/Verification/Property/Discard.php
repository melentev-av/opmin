<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

/**
 * Thrown by a property check for an input that says nothing: the original version hangs on it,
 * the input cannot be built. Not a failure; the runner draws another input.
 *
 * @internal
 */
final class Discard extends \RuntimeException {}
