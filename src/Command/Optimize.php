<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Minimize opcodes: Rector and LLM stages with behavior verification.
 *
 * @internal
 */
#[AsCommand(
    name: 'optimize',
    description: 'Minimize opcodes: Rector and LLM stages with behavior verification',
)]
final class Optimize extends NotImplemented
{
    protected const string STAGE = 'M3';

    public function configure(): void
    {
        parent::configure();
        $this->addArgument('path', InputArgument::IS_ARRAY, 'Files, directories, globs or a git URL');
        $this->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Git ref (tag, branch) when the path is a git URL');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Change nothing, only write the report and the patch');
        $this->addOption('review', null, InputOption::VALUE_NONE, 'Confirm every change interactively');
        $this->addOption('resume', null, InputOption::VALUE_NONE, 'Continue an interrupted run');
        $this->addOption('guard-perf', null, InputOption::VALUE_NONE, 'Roll back changes that make code slower');
        $this->addOption('with-standard-rector', null, InputOption::VALUE_NONE, 'Enable the standard Rector rules for this run');
        $this->addOption('without-standard-rector', null, InputOption::VALUE_NONE, 'Disable the standard Rector rules for this run');
        $this->addOption('rector-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only the given Rector rule (FQCN), repeatable');
        $this->addOption('allow-unverified', null, InputOption::VALUE_NONE, 'Change functions whose behavior is not proven');
        $this->addOption('allow-public-signatures', null, InputOption::VALUE_NONE, 'Allow native type changes of public overridable methods');
        $this->addOption('force-public-api', null, InputOption::VALUE_NONE, 'Touch the public API in package mode');
        $this->addOption('mutation-check', null, InputOption::VALUE_NONE, 'Report the mutation score of the target functions first');
        $this->addOption('no-docker', null, InputOption::VALUE_NONE, 'Do not re-run inside Docker in git package mode');
        $this->addOption('allow-scripts', null, InputOption::VALUE_NONE, 'Run composer scripts and plugins of a git package');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask before running tests of foreign code');
    }
}
