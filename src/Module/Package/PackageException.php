<?php

declare(strict_types=1);

namespace Opmin\Module\Package;

/**
 * A git package cannot be cloned, installed or run: the message says what to do.
 *
 * @internal
 */
final class PackageException extends \RuntimeException {}
