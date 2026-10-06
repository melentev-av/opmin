<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Count opcodes per function, method and closure.
 *
 * @internal
 */
#[AsCommand(
    name: 'count',
    description: 'Count opcodes per function, method and closure',
)]
final class Count extends NotImplemented
{
    protected const string STAGE = 'M1';

    public function configure(): void
    {
        parent::configure();
        $this->addArgument('path', InputArgument::IS_ARRAY, 'Files or directories to analyze');
        $this->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only functions matching the FQN pattern, e.g. App\\Service\\*');
        $this->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Comma-separated paths to skip');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table | json', 'table');
    }
}
