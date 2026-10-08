<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Opmin\Module\Verification\Compare\Difference;

/**
 * Thrown by the property check: the versions differ on the input.
 *
 * @internal
 */
final class Mismatch extends \RuntimeException
{
    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $changed
     */
    public function __construct(
        public readonly Difference $difference,
        public readonly array $original,
        public readonly array $changed,
    ) {
        parent::__construct((string) $difference);
    }
}
