<?php

declare(strict_types=1);

namespace Opmin\Command;

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
            $context = $this->context($project, $php, $session, $target);
        } catch (PhpBinaryException|\InvalidArgumentException|\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $output->write($format === 'json' ? self::json($context->toArray()) : $context->render(), false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
