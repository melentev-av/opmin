<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Install the Claude Code skill opcode-minimize.
 *
 * @internal
 */
#[AsCommand(
    name: 'skill:install',
    description: 'Install the Claude Code skill opcode-minimize',
)]
final class SkillInstall extends NotImplemented
{
    protected const string STAGE = 'M4';
}
