<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

/**
 * Thrown by a property check when further inputs would say nothing either (the original version
 * hangs on every one of them): the run ends without a failure. The check throws it for every input
 * after the first one too, so the rest of the run costs no calls; the caller decides the verdict.
 *
 * @internal
 */
final class Stop extends \RuntimeException {}
