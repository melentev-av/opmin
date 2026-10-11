<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Check\Ci\CiSetup;
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
use Symfony\Component\Console\Input\ArrayInput;
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
 * defaults; the rest keeps the defaults. It ends with the next commands (`doctor`, `count`, a dry run) and,
 * on a terminal or with `--doctor`, runs `doctor` on the written config; the exit code stays 0.
 *
 * ```bash
 * opmin init
 * opmin init -n --doctor
 * opmin init --config=./custom.yaml --overwrite
 * opmin init --no-skill
 * opmin init --ci=github      # only .github/workflows/opcodes.yml (gitlab: .gitlab/opmin.yml)
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
        $this->addOption(
            'doctor',
            null,
            InputOption::VALUE_NEGATABLE,
            'Check the written config with opmin doctor (default: ask on a terminal, skip otherwise)',
        );
        $this->addOption('ci', null, InputOption::VALUE_REQUIRED, 'Only write the CI guard job for github | gitlab, with the PHP minor of php.binary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        /** @var StyleInterface $style */
        $style = $this->container->get(StyleInterface::class);

        /** @var string|null $ci */
        $ci = $input->getOption('ci');
        if ($ci !== null) {
            return (new CiSetup($style))->run($ci, $this->getTargetConfigPath($input), $input);
        }

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

        $this->shouldRunDoctor($input) and $this->runDoctor($configPath, $style);
        $this->printNextSteps($input, $style, $configPath, $detected);

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

    /**
     * `--doctor` / `--no-doctor`; without either, asks only on a terminal: an agent or a CI step without `-n`
     * would block reading a pipe that stays open.
     */
    private function shouldRunDoctor(InputInterface $input): bool
    {
        /** @var bool|null $flag */
        $flag = $input->getOption('doctor');
        if ($flag !== null) {
            return $flag;
        }

        if (!$input->isInteractive() || !\defined('STDIN') || !\stream_isatty(\STDIN)) {
            return false;
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return (bool) $helper->ask(
            $input,
            $this->container->get(OutputInterface::class),
            new ConfirmationQuestion('Check the environment with opmin doctor now? [Y/n] ', true),
        );
    }

    /**
     * Runs `doctor` on the written config: the container of `init` is built without it, so it would check the
     * defaults. The exit code of `init` stays 0 — the config was written; `opmin doctor` exits with 1 itself.
     */
    private function runDoctor(Path $configPath, StyleInterface $style): void
    {
        $application = $this->getApplication();
        $doctor = $application !== null && $application->has('doctor') ? $application->find('doctor') : null;
        $doctor instanceof Doctor or $doctor = new Doctor();

        $input = new ArrayInput(['--config' => (string) $configPath]);
        $input->setInteractive(false);
        $style->newLine();
        $code = $doctor->run($input, $this->container->get(OutputInterface::class));

        $blockers = $doctor->blockers();
        $style->text(match (true) {
            $code === Command::INVALID => '<error>doctor: the written config does not load, see above</error>',
            $blockers > 0 => "<error>doctor: {$blockers} blocker(s)</error>",
            default => '<info>doctor: 0 blocker(s)</info>',
        });
    }

    /**
     * What to run after `init`, printed always (also with `-n`): nothing of it is run here — `count` on a cold
     * cache is slow and a dry run is the user's decision.
     *
     * @param array<non-empty-string, array{mixed, non-empty-string}> $detected
     */
    private function printNextSteps(InputInterface $input, StyleInterface $style, Path $configPath, array $detected): void
    {
        $config = $input->getOption('config') === null ? '' : ' --config=' . (string) $configPath;
        /** @var Schema\Project $project */
        $project = $this->container->get(Schema\Project::class);
        /** @var list<string> $paths */
        $paths = \is_array($detected['paths'][0] ?? null) ? $detected['paths'][0] : $project->paths;
        $runs = $input->getOption('config') === null ? Path::create('.') : $configPath->parent();
        $report = (string) $runs->join(Layout::DIR, 'report_<ts>.md');
        $patch = (string) $runs->join(Layout::RUNS, '<ts>', 'opmin.patch');

        $steps = [
            "opmin doctor{$config}" => 'check php.binary, OPcache, the tests, PHPStan',
            "opmin count{$config} --top=10" => 'the functions with the most opcodes',
            "opmin optimize{$config} --dry-run " . \implode(' ', $paths) => "change nothing: the report goes to {$report}, the patch to {$patch}",
        ];
        $width = \max(\array_map('strlen', \array_keys($steps)));

        $style->newLine();
        $style->text('<options=bold>Next:</>');
        foreach ($steps as $command => $what) {
            $style->text(\sprintf('  %s  <fg=gray># %s</>', \str_pad($command, $width), $what));
        }
    }
}
