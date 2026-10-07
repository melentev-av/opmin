<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Info;
use Opmin\Module\Skill\Skill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Updates an installed Claude Code skill `opcode-minimize` to the version of the running opmin.
 *
 * Exit codes: 0 — updated or up to date; 1 — the skill is not installed there, or cannot be written.
 *
 * @internal
 */
#[AsCommand(
    name: 'skill:update',
    description: 'Update the Claude Code skill to the installed opmin version',
)]
final class SkillUpdate extends Base
{
    public function configure(): void
    {
        parent::configure();
        $this->addOption('global', null, InputOption::VALUE_NONE, 'Update the skill in ~/.claude/skills instead of the current directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        /** @var StyleInterface $style */
        $style = $this->container->get(StyleInterface::class);
        try {
            $skills = SkillInstall::skillsDir($input);
            $installed = Skill::installedVersion($skills);
            if ($installed === null) {
                $style->error('The skill is not installed in ' . (string) Skill::dir($skills) . ': use `opmin skill:install`.');
                return Command::FAILURE;
            }

            if ($installed === Info::version()) {
                $style->success("The skill is up to date ({$installed}).");
                return Command::SUCCESS;
            }

            Skill::install($skills);
        } catch (\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::FAILURE;
        }

        $style->success(\sprintf('The skill is updated: %s → %s.', $installed, Info::version()));

        return Command::SUCCESS;
    }

    protected function getConfigFile(InputInterface $input): ?string
    {
        return null;
    }
}
