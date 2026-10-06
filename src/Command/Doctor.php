<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Check the environment: php.binary, OPcache, git, tests, PHPStan.
 *
 * @internal
 */
#[AsCommand(
    name: 'doctor',
    description: 'Check the environment: php.binary, OPcache, git, tests, PHPStan',
)]
final class Doctor extends NotImplemented
{
    protected const string STAGE = 'M7';
}
