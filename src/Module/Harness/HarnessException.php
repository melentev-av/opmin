<?php

declare(strict_types=1);

namespace Opmin\Module\Harness;

/**
 * The harness cannot work with the code: loading failed, the target does not exist, a request was
 * refused. Not a behavior of the code under test — the function cannot be verified.
 *
 * @internal
 */
final class HarnessException extends \RuntimeException {}
