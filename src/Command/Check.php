<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Fail when opcodes grew compared to the baseline.
 *
 * @internal
 */
#[AsCommand(
    name: 'check',
    description: 'Fail when opcodes grew compared to the baseline',
)]
final class Check extends NotImplemented
{
    protected const string STAGE = 'M6';

    public function configure(): void
    {
        parent::configure();
        $this->addOption('base', null, InputOption::VALUE_REQUIRED, 'Base ref for the changed-files mode (default: check.base_ref)');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Check the whole project, not only changed files');
        $this->addOption('update-baseline', null, InputOption::VALUE_NONE, 'Write decreased counts to the baseline');
        $this->addOption('suggest', null, InputOption::VALUE_NONE, 'Show which Rector rule would bring opcodes back');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table | json | github | gitlab | checkstyle', 'table');
    }
}
