<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Config\ConfigWriter;
use Opmin\Module\Config\Schema;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\InitDetector;
use Opmin\Module\Project\Layout;
use Opmin\Module\Skill\Skill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Creates `opmin.yaml` with every key, its value and a comment, and puts the Claude Code skill
 * `opcode-minimize` into `.claude/skills/` next to it (unless `--no-skill`). The PHP target, code
 * directories, test runner, formatter and PHPStan of the project are detected and written instead of the
 * defaults; the rest keeps the defaults.
 *
 * ```bash
 * opmin init
 * opmin init --config=./custom.yaml --overwrite
 * opmin init --no-skill
 * ```
 *
 * @internal
 */
#[AsCommand(
    name: 'init',
    description: 'Create opmin.yaml with all keys and defaults',
)]
final class Init extends Base
{
    protected const bool CHECK_VERSION = false;
    private const DEFAULT_CONFIG_PATH = 'opmin.yaml';

    public function configure(): void
    {
        parent::configure();
        $this->addOption(
            'overwrite',
            null,
            InputOption::VALUE_NONE,
            'Overwrite existing configuration file without confirmation',
        );
        $this->addOption('no-skill', null, InputOption::VALUE_NONE, 'Do not put the Claude Code skill into .claude/skills');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        /** @var StyleInterface $style */
        $style = $this->container->get(StyleInterface::class);

        $configPath = $this->getTargetConfigPath($input);
        if ($this->shouldAbortDueToExistingFile($input, $style, $configPath)) {
            return Command::FAILURE;
        }

        /** @var Schema\Php $php */
        $php = $this->container->get(Schema\Php::class);
        try {
            $binary = (new PhpBinaryProbe())->probe($php->binary);
        } catch (PhpBinaryException) {
            # No php.binary yet: composer.json gives the target, doctor tells what to install.
            $binary = null;
        }

        $detected = InitDetector::detect($configPath->parent(), $binary);
        \file_put_contents((string) $configPath, ConfigWriter::render(\array_map(static fn(array $found): mixed => $found[0], $detected)));
        $style->success("Configuration file created: {$configPath}");
        foreach ($detected as $key => $found) {
            $style->text(\sprintf('  %s: %s  <fg=gray>(%s)</>', $key, Yaml::dump($found[0], 0), $found[1]));
        }

        Layout::ignore($configPath->parent()) and $style->text('Added ' . Layout::GITIGNORE . ' to .gitignore');
        $input->getOption('no-skill') or $this->installSkill($configPath->parent(), $style);

        return Command::SUCCESS;
    }

    /**
     * The existing config is not read here: `init` must work exactly when the config is missing or
     * broken, so the target path comes straight from `--config`.
     */
    protected function getConfigFile(InputInterface $input): ?string
    {
        return null;
    }

    private function getTargetConfigPath(InputInterface $input): Path
    {
        /** @var string|null $configOption */
        $configOption = $input->getOption('config');

        return Path::create($configOption ?? self::DEFAULT_CONFIG_PATH);
    }

    private function shouldAbortDueToExistingFile(
        InputInterface $input,
        StyleInterface $style,
        Path $configPath,
    ): bool {
        if (!$configPath->exists() || $input->getOption('overwrite')) {
            return false;
        }

        if (!$input->isInteractive()) {
            $style->error("Configuration file already exists: {$configPath}");
            $style->text('Use --overwrite to replace it or specify a different path with --config.');
            return true;
        }

        $question = new ConfirmationQuestion(
            "Configuration file already exists at {$configPath}. Overwrite it? [y/N] ",
            false,
        );

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return !$helper->ask($input, $this->container->get(OutputInterface::class), $question);
    }

    /**
     * Puts the skill next to the config, or updates an older copy.
     */
    private function installSkill(Path $root, StyleInterface $style): void
    {
        $skills = $root->join('.claude', 'skills');
        if (Skill::installedVersion($skills) === Info::version()) {
            return;
        }

        try {
            $style->text('Claude Code skill: ' . (string) Skill::install($skills));
        } catch (\RuntimeException $e) {
            $style->warning('The Claude Code skill is not installed: ' . $e->getMessage());
        }
    }
}
