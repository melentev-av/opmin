<?php

declare(strict_types=1);

namespace Opmin\Module\Config\Schema;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;
use Opmin\Module\Common\Internal\Attribute\InflectableConfig;

/**
 * How the project's own tests are run.
 *
 * @internal
 */
#[InflectableConfig]
final class Tests
{
    #[ConfigKey('tests.runner', 'Test runner of the project: auto | phpunit | pest | testo | command | none')]
    public TestRunner $runner = TestRunner::Auto;

    /** @var non-empty-string|null */
    #[ConfigKey('tests.command', 'Command for runner "command", or an override of the runner binary')]
    public ?string $command = null;
}
