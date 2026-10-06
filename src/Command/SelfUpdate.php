<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Update the opmin binary to the latest release.
 *
 * @internal
 */
#[AsCommand(
    name: 'self-update',
    description: 'Update the opmin binary to the latest release',
)]
final class SelfUpdate extends NotImplemented
{
    protected const string STAGE = 'M7';
}
