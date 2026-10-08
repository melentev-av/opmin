<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Dump;

/**
 * The OPcache dump does not have the expected format.
 *
 * @internal
 */
final class DumpParseException extends \RuntimeException {}
