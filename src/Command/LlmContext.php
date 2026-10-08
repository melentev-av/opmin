<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Config\Schema;
use Opmin\Module\Llm\Context;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Optimize\Units;
use Opmin\Module\Php\PhpBinaryException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the model needs to rewrite one target of the session (brief, «Stage B», step 2): the current
 * source, the opcodes, the dynamic constructs with what they forbid, the readability and signature
 * rules, the rejected attempts.
 *
 * ```bash
 * opmin llm:context 'App\Service\Pricing::total'
 * ```
 *
 * Exit codes: 0 — printed; 2 — no session, not a target of it, invalid usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'llm:context',
    description: 'Print what the model needs to rewrite one function of the LLM session',
)]
final class LlmContext extends LlmStage
{
    public function configure(): void
    {
        parent::configure();
        $this->addArgument('function', InputArgument::REQUIRED, 'Key of the function, as `llm:targets` prints it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);
        try {
            $format = $this->format($input);
            [$project, , $php] = $this->project();
            $session = $this->session($project, $input);
            $key = (string) $input->getArgument('function');
            $target = $session->target($key) ?? throw new \InvalidArgumentException(
                "`{$key}` is not a target of the session {$project->relative($session->runDir)}: see `opmin llm:targets`.",
            );
            $file = $project->root->join($target->file);
            $cacheDir = $this->cacheDir();
            $counter = $this->counter($php, $cacheDir);
            $result = $counter->count($project, [$file]);
            $result->errors === [] or throw new \RuntimeException(\implode("\n", $result->errors));
            $listings = $counter->listings($project, $file);
        } catch (PhpBinaryException|\InvalidArgumentException|\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $counts = [];
        foreach (ReferenceIndex::build($project, $cacheDir)->apply($result->functions) as $function) {
            $counts[$function->key] = $function;
        }

        $units = Units::of((string) \file_get_contents((string) $file), $target->file);
        if (!isset($units->units[$target->key])) {
            $style->error("`{$target->key}` is no longer in {$target->file}.");
            return Command::INVALID;
        }

        /** @var Schema\Llm $llm */
        $llm = $this->container->get(Schema\Llm::class);
        /** @var Schema\Readability $readability */
        $readability = $this->container->get(Schema\Readability::class);
        /** @var Schema\Signatures $signatures */
        $signatures = $this->container->get(Schema\Signatures::class);
        $context = new Context(
            $target,
            $units,
            \array_filter($counts, static fn(FunctionCount $f): bool => $f->file === $target->file),
            $listings,
            $session->attempts($target->key),
            $llm->attemptsPerFunction,
            $readability,
            $signatures,
        );

        $output->write($format === 'json' ? self::json($context->toArray()) : $context->render(), false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
