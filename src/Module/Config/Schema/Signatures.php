<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * What may change in function signatures and docblocks.
 *
 * @internal
 */
#[InflectableConfig]
final class Signatures
{
    #[ConfigKey('signatures.phpdoc', 'Refine phpdoc types')]
    public bool $phpdoc = true;

    #[ConfigKey('signatures.native_types', 'Native type changes: none | non_overridable | all')]
    public NativeTypesPolicy $nativeTypes = NativeTypesPolicy::NonOverridable;

    #[ConfigKey('signatures.public_api', 'Touch public overridable methods')]
    public bool $publicApi = false;
}
