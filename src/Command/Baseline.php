<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Write opmin.baseline.json with the current opcode counts.
 *
 * @internal
 */
#[AsCommand(
    name: 'baseline',
    description: 'Write opmin.baseline.json with the current opcode counts',
)]
final class Baseline extends NotImplemented
{
    protected const string STAGE = 'M6';
}
