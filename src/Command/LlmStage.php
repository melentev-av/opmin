<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Config\Schema;
use Opmin\Module\Llm\Session;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\Project;
use Opmin\Module\Project\Targets;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The commands the skill of the LLM stage calls (brief, «Stage B»): they share a session in
 * `runs/<ts>/` (the latest one, or `--run`) and print JSON with `--format=json`.
 *
 * @internal
 */
abstract class LlmStage extends Stage
{
    /** @var list<non-empty-string> */
    protected const array FORMATS = ['text', 'json'];

    public function configure(): void
    {
        parent::configure();
        $this->addOption('run', null, InputOption::VALUE_REQUIRED, 'Run directory of the LLM session (default: the latest one)');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: ' . \implode(' | ', static::FORMATS), static::FORMATS[0]);
    }

    protected static function json(mixed $data): string
    {
        return \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function format(InputInterface $input): string
    {
        $format = (string) $input->getOption('format');
        \in_array($format, static::FORMATS, true) or throw new \InvalidArgumentException(
            "Unknown format `{$format}`: use " . \implode(' or ', static::FORMATS) . '.',
        );

        return $format;
    }

    /**
     * @param list<string> $arguments Target paths; empty — the project of the current directory.
     * @return array{Project, list<Path>, PhpBinary}
     * @throws \InvalidArgumentException
     * @throws \Opmin\Module\Php\PhpBinaryException
     */
    protected function project(array $arguments = []): array
    {
        /** @var Schema\Php $phpConfig */
        $phpConfig = $this->container->get(Schema\Php::class);
        /** @var Schema\Project $projectConfig */
        $projectConfig = $this->container->get(Schema\Project::class);
        $php = (new PhpBinaryProbe())->probe($phpConfig->binary);
        [$project, $paths] = Targets::resolve($arguments, Path::create((string) \getcwd()), $projectConfig);

        return [$project, $paths, $php];
    }

    /**
     * @throws \RuntimeException When there is no session.
     */
    protected function session(Project $project, InputInterface $input): Session
    {
        /** @var mixed $run */
        $run = $input->getOption('run');

        return Session::open(
            $project->root->join('runs'),
            \is_string($run) && $run !== '' ? Targets::absolute($run, Path::create((string) \getcwd())) : null,
        );
    }
}
