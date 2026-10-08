<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Skill\Skill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Installs the Claude Code skill `opcode-minimize`: into `.claude/skills/` of the current directory,
 * or with `--global` into `~/.claude/skills/` (available everywhere, a clone of a git package too).
 *
 * ```bash
 * opmin skill:install
 * opmin skill:install --global
 * ```
 *
 * Exit codes: 0 — installed or up to date; 1 — another version is installed (use `skill:update` or
 * `--force`), or the skill cannot be written.
 *
 * @internal
 */
#[AsCommand(
    name: 'skill:install',
    description: 'Install the Claude Code skill opcode-minimize',
)]
final class SkillInstall extends Base
{
    protected const bool CHECK_VERSION = false;

    /**
     * The skills directory a skill command works on.
     */
    public static function skillsDir(InputInterface $input): Path
    {
        return $input->getOption('global')
            ? Skill::globalDir()
            : Path::create((string) \getcwd())->join('.claude', 'skills');
    }

    public function configure(): void
    {
        parent::configure();
        $this->addOption('global', null, InputOption::VALUE_NONE, 'Install into ~/.claude/skills instead of the current directory');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Replace another installed version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        /** @var StyleInterface $style */
        $style = $this->container->get(StyleInterface::class);
        try {
            $skills = self::skillsDir($input);
            $installed = Skill::installedVersion($skills);
            if ($installed === Info::version()) {
                $style->success("The skill {$installed} is installed already: " . (string) Skill::dir($skills));
                return Command::SUCCESS;
            }

            if ($installed !== null && !$input->getOption('force')) {
                $style->error("The skill {$installed} is installed in " . (string) Skill::dir($skills) . ': use `opmin skill:update` (or --force).');
                return Command::FAILURE;
            }

            $file = Skill::install($skills);
        } catch (\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::FAILURE;
        }

        $style->success(\sprintf('The skill %s %s is installed: %s', Skill::NAME, Info::version(), (string) $file));

        return Command::SUCCESS;
    }

    /**
     * The skill does not depend on the config: it installs anywhere, even where `opmin.yaml` is broken.
     */
    protected function getConfigFile(InputInterface $input): ?string
    {
        return null;
    }
}
