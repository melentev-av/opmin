<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Info;
use Opmin\Module\Config\Schema;
use Opmin\Module\Llm\Session;
use Opmin\Module\Llm\Target;
use Opmin\Module\Llm\TargetSelector;
use Opmin\Module\Php\PhpBinaryException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Starts a session of the LLM stage: counts the targets and picks the `llm.top_n` top-level functions
 * with the most opcodes (brief, «Stage B», step 1). The session lives in `runs/<ts>/`.
 *
 * ```bash
 * opmin llm:targets src --format=json
 * ```
 *
 * Exit codes: 0 — started (also with no targets); 1 — some files cannot be counted; 2 — invalid usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'llm:targets',
    description: 'Start an LLM session: pick the functions with the most opcodes',
)]
final class LlmTargets extends LlmStage
{
    public function configure(): void
    {
        parent::configure();
        $this->addArgument('path', InputArgument::IS_ARRAY, 'Files, directories or globs (default: `paths` from the config)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);
        try {
            $format = $this->format($input);
            /** @var list<string> $arguments */
            $arguments = $input->getArgument('path');
            [$project, $paths, $php] = $this->project($arguments);
            $files = $this->files($project, $paths);
        } catch (PhpBinaryException|\InvalidArgumentException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        /** @var Schema\Llm $llm */
        $llm = $this->container->get(Schema\Llm::class);
        /** @var Schema\Ignore $ignore */
        $ignore = $this->container->get(Schema\Ignore::class);
        $counts = $this->counter($php, $this->cacheDir($project))->count($project, $files);
        $targets = (new TargetSelector($project, $ignore))->select($counts, $llm->topN);
        $session = Session::start($project->root->join('runs', \date('Ymd-His')), $targets, $php->version);

        $data = [
            'run' => $project->relative($session->runDir),
            'opmin' => Info::version(),
            'php' => $php->version,
            'attempts_per_function' => $llm->attemptsPerFunction,
            'targets' => \array_map(static fn(Target $t): array => $t->toArray(), $targets),
            'errors' => $counts->errors,
        ];
        if ($format === 'json') {
            $output->write(self::json($data), false, OutputInterface::OUTPUT_RAW);
        } else {
            $out = new SymfonyStyle($input, $output);
            $out->writeln(\sprintf('LLM session %s, PHP %s, %d attempt(s) per function.', $data['run'], $php->version, $llm->attemptsPerFunction));
            $targets === [] or $out->table(
                ['Function', 'File', 'Opcodes'],
                \array_map(static fn(Target $t): array => [$t->key, "{$t->file}:{$t->line}", $t->opsOpt], $targets),
            );
            $targets === [] and $out->writeln('No functions to rewrite.');
        }

        foreach ($counts->errors as $file => $error) {
            $style->warning("{$file}: {$error}");
        }

        return $counts->errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
