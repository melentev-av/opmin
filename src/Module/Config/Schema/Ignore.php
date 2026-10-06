<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * Code that must never be changed.
 *
 * @internal
 */
#[InflectableConfig]
final class Ignore
{
    /** @var list<non-empty-string> */
    #[ConfigKey('ignore.paths', 'Paths to never change', list: true)]
    public array $paths = [];

    /** @var list<non-empty-string> */
    #[ConfigKey('ignore.functions', 'Functions to never change (FQN, * wildcard allowed)', list: true)]
    public array $functions = [];
}
