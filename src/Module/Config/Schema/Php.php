<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * PHP used for everything that depends on the production version: opcode counting, the
 * differential-testing harness, the project's tests and PHPStan.
 *
 * @internal
 */
#[InflectableConfig]
final class Php
{
    /** @var non-empty-string */
    #[ConfigKey('php.binary', 'PHP binary used to compile, count and run the code; must match production')]
    public string $binary = 'php';

    /** @var non-empty-string|null */
    #[ConfigKey('php.target', 'Target PHP version (e.g. "8.3"); null — from composer.json config.platform.php or require.php')]
    public ?string $target = null;
}
