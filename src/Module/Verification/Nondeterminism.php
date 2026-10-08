<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Opmin\Module\Verification\Compare\Difference;

/**
 * Thrown by the property check: one version gave two different results for the same input.
 *
 * @internal
 */
final class Nondeterminism extends \RuntimeException
{
    public function __construct(
        public readonly Difference $difference,
        public readonly string $version,
    ) {
        parent::__construct("The {$version} version is not deterministic: {$difference}");
    }
}
