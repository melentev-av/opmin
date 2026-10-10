<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * How a run inside a git working tree treats the tree.
 *
 * @internal
 */
#[InflectableConfig]
final class Git
{
    #[ConfigKey('git.require_clean', 'off — no check (--with-git turns on targets for one run); targets — the files the run may change must be clean; all — the whole tree')]
    public RequireClean $requireClean = RequireClean::Off;
}
