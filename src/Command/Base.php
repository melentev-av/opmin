<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Container\Container;
use Internal\Path;
use Opmin\Bootstrap;
use Opmin\Info;
use Opmin\Module\Config\ConfigLoader;
use Opmin\Module\Config\ConfigSchema;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\Schema;
use Opmin\Module\Project\Targets;
use Opmin\Module\Release\ProjectVersion;
use Opmin\Service\Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base abstract class for all opmin commands.
 *
 * Provides common functionality for command initialization, container setup,
 * and configuration handling.
 *
 * ```php
 * final class CustomCommand extends Base
 * {
 *     protected function execute(InputInterface $input, OutputInterface $output): int
 *     {
 *         parent::execute($input, $output);
 *         // Command implementation
 *         return Command::SUCCESS;
 *     }
 * }
 * ```
 *
 * @internal
 */
abstract class Base extends Command
{
    /** @var bool Whether the command refuses to run when the project pins another opmin version. */
    protected const bool CHECK_VERSION = true;

    /** @var Logger Service for logging command execution */
    protected Logger $logger;

    /** @var Container IoC container with services */
    protected Container $container;

    /** @var Path Directory of the config file in use, the current directory without one. */
    protected Path $configDir;

    public static function getCommandName(): ?string
    {
        if ($attributes = (new \ReflectionClass(static::class))->getAttributes(AsCommand::class)) {
            /** @var AsCommand $attribute */
            $attribute = $attributes[0]->newInstance();
            return $attribute->name;
        }

        return null;
    }

    public function configure(): void
    {
        parent::configure();
        $this->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to the configuration file (default: opmin.yaml, opmin.yaml.dist)');
        $this->addOption(
            'set',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Override a config key for this run, e.g. --set=verification.seed=7',
        );
    }

    /**
     * Reports configuration errors as a plain message with exit code {@see Command::INVALID}
     * instead of an exception trace.
     */
    #[\Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConfigException $e) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            (new SymfonyStyle($input, $error))->error($e->getMessage());

            return Command::INVALID;
        }
    }

    /**
     * Initializes the command execution environment: loads the config and sets up the container.
     *
     * @return int Command success code
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->logger = new Logger($output);
        $config = $this->getConfigFile($input);
        $cwd = Path::create((string) \getcwd());
        $this->configDir = $config === null ? $cwd : Targets::absolute($config, $cwd)->parent();

        /** @var list<string> $overrides */
        $overrides = $input->getOption('set');
        /** @var array<string, mixed> $options */
        $options = $input->getOptions();
        /** @var array<string, mixed> $arguments */
        $arguments = $input->getArguments();
        $this->container = $container = Bootstrap::init()->withConfig(
            file: $config,
            inputOptions: $options,
            inputArguments: $arguments,
            environment: \getenv(),
            overrides: $overrides,
        )->finish();

        $container->set($input, InputInterface::class);
        $container->set($output, OutputInterface::class);
        $container->set(new SymfonyStyle($input, $output), StyleInterface::class);
        $container->set($this->logger);

        # Hydrate every config section now: a wrong value in the environment or in --set
        # must fail the run before any work starts, not when the section is first used.
        foreach (ConfigSchema::SECTIONS as $section) {
            $container->get($section);
        }

        if (static::CHECK_VERSION) {
            /** @var Schema\Project $project */
            $project = $container->get(Schema\Project::class);
            ProjectVersion::check(Info::version(), $project->requires, $this->configDir);
        }

        return Command::SUCCESS;
    }

    /**
     * `cache.dir`, absolute. A relative one resolves next to the config, not in the project root: in a
     * monorepo the root is the package of the analyzed paths, and every package would get a cache.
     */
    protected function cacheDir(): Path
    {
        /** @var Schema\Cache $cacheConfig */
        $cacheConfig = $this->container->get(Schema\Cache::class);
        $cacheDir = Path::create($cacheConfig->dir);

        return $cacheDir->isAbsolute() ? $cacheDir : $this->configDir->join($cacheConfig->dir);
    }

    /**
     * Resolves the configuration file: `--config`, otherwise `opmin.yaml` / `opmin.yaml.dist`
     * in the current directory.
     *
     * @return non-empty-string|null
     */
    protected function getConfigFile(InputInterface $input): ?string
    {
        /** @var string|null $config */
        $config = $input->getOption('config');

        return ConfigLoader::locate((string) \getcwd(), $config);
    }
}
