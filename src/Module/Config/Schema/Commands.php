<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * External commands of the project.
 *
 * @internal
 */
#[InflectableConfig]
final class Commands
{
    /** @var non-empty-string|null */
    #[ConfigKey('commands.phpstan', 'PHPStan command of the project; null — skip the static check')]
    public ?string $phpstan = 'vendor/bin/phpstan analyse --no-progress --error-format=json';

    /** @var non-empty-string|null */
    #[ConfigKey('commands.format', 'Formatter run on changed files ({files} is replaced); null — detect (Pint, PHP-CS-Fixer, ECS, PHPCBF), none — no formatter')]
    public ?string $format = null;
}
