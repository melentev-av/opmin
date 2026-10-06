<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Compare two count reports function by function.
 *
 * @internal
 */
#[AsCommand(
    name: 'diff',
    description: 'Compare two count reports function by function',
)]
final class Diff extends NotImplemented
{
    protected const string STAGE = 'M1';

    public function configure(): void
    {
        parent::configure();
        $this->addArgument('before', InputArgument::REQUIRED, 'Report before (JSON)');
        $this->addArgument('after', InputArgument::REQUIRED, 'Report after (JSON)');
    }
}
