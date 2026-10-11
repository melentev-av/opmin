<?php

declare(strict_types=1);

namespace Opmin\Command;

use Internal\Path;
use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Config\Schema;
use Opmin\Module\Llm\Context;
use Opmin\Module\Llm\Session;
use Opmin\Module\Llm\Target;
use Opmin\Module\Optimize\Units;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryProbe;
use Opmin\Module\Project\Layout;
use Opmin\Module\Project\Project;
use Opmin\Module\Project\Targets;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The commands the skill of the LLM stage calls (brief, «Stage B»): they share a session in
 * `.opmin/runs/<ts>/` (the latest one, or `--run`) and print JSON with `--format=json`.
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
            $project->root->join(Layout::RUNS),
            \is_string($run) && $run !== '' ? Targets::absolute($run, Path::create((string) \getcwd())) : null,
        );
    }

    /**
     * What the model needs to rewrite `$target`, from the current state of its file.
     *
     * @throws \RuntimeException When the file does not count or the function is no longer in it.
     */
    protected function context(Project $project, PhpBinary $php, Session $session, Target $target): Context
    {
        $file = $project->root->join($target->file);
        $counter = $this->counter($php);
        $result = $counter->count($project, [$file]);
        $result->errors === [] or throw new \RuntimeException(\implode("\n", $result->errors));
        $listings = $counter->listings($project, $file);

        $counts = [];
        foreach (ReferenceIndex::build($project, $this->cacheDir(), $this->cacheStore())->apply($result->functions) as $function) {
            $function->file === $target->file and $counts[$function->key] = $function;
        }

        $units = Units::of((string) \file_get_contents((string) $file), $target->file);
        isset($units->units[$target->key]) or throw new \RuntimeException("`{$target->key}` is no longer in {$target->file}.");

        /** @var Schema\Llm $llm */
        $llm = $this->container->get(Schema\Llm::class);
        /** @var Schema\Readability $readability */
        $readability = $this->container->get(Schema\Readability::class);
        /** @var Schema\Signatures $signatures */
        $signatures = $this->container->get(Schema\Signatures::class);

        return new Context(
            $target,
            $units,
            $counts,
            $listings,
            $session->attempts($target->key),
            $llm->attemptsPerFunction,
            $readability,
            $signatures,
        );
    }
}
