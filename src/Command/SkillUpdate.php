<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Update the Claude Code skill to the installed opmin version.
 *
 * @internal
 */
#[AsCommand(
    name: 'skill:update',
    description: 'Update the Claude Code skill to the installed opmin version',
)]
final class SkillUpdate extends NotImplemented
{
    protected const string STAGE = 'M7';
}
