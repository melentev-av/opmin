<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Locate;

/**
 * The OPcache dump and the source disagree: opcodes cannot be attributed to functions reliably.
 *
 * @internal
 */
final class MatchException extends \RuntimeException {}
