<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Report;

/**
 * A report cannot be read, or two reports cannot be compared.
 *
 * @internal
 */
final class ReportException extends \RuntimeException {}
