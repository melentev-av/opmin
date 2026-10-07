<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Config\ConfigWriter;
use Opmin\Module\Skill\Skill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Creates `opmin.yaml` with every key, its default value and a comment, and puts the Claude Code
 * skill `opcode-minimize` into `.claude/skills/` next to it (unless `--no-skill`).
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
    private const DEFAULT_CONFIG_PATH = 'opmin.yaml';
    private const CACHE_IGNORE_LINE = '/.opmin-cache/';

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

        \file_put_contents((string) $configPath, ConfigWriter::render());
        $style->success("Configuration file created: {$configPath}");

        $this->ignoreCacheDirectory($configPath->parent(), $style);
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
     * Adds the cache directory to the project's `.gitignore` when the project is a git repository
     * and the line is not there yet.
     */
    private function ignoreCacheDirectory(Path $root, StyleInterface $style): void
    {
        $gitignore = $root->join('.gitignore');
        if (!$gitignore->exists() && !$root->join('.git')->exists()) {
            return;
        }

        $content = $gitignore->exists() ? (string) \file_get_contents((string) $gitignore) : '';
        $lines = \array_map('trim', \explode("\n", $content));
        if (\array_intersect($lines, [self::CACHE_IGNORE_LINE, '.opmin-cache', '.opmin-cache/', '/.opmin-cache']) !== []) {
            return;
        }

        $prefix = $content === '' || \str_ends_with($content, "\n") ? '' : "\n";
        \file_put_contents((string) $gitignore, $prefix . self::CACHE_IGNORE_LINE . "\n", \FILE_APPEND);
        $style->text('Added ' . self::CACHE_IGNORE_LINE . ' to .gitignore');
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
