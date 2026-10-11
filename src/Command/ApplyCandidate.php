<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Module\Config\Schema;
use Opmin\Module\Llm\Attempt;
use Opmin\Module\Llm\Context;
use Opmin\Module\Llm\Session;
use Opmin\Module\Llm\Target;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Project\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies a rewritten function proposed by the LLM stage (brief, «Stage B», steps 3–5).
 *
 * The candidate is the new source of **only this function** (with its docblock, when that changes
 * too); it replaces the function in the current file, nothing else may change. Then the step of
 * `optimize`: format → `php -l` → count → readability, `forbid_patterns`, signatures → behavior
 * (PHPStan, the project's tests, differential tests) → a commit, or nothing is written. Every attempt
 * is recorded in the session, at most `llm.attempts_per_function` per function.
 *
 * ```bash
 * opmin apply-candidate 'App\Service\Pricing::total' .opmin/runs/20261007-120000/candidate.php --format=json
 * opmin apply-candidate 'App\Service\Pricing::total' - < candidate.php
 * opmin apply-candidate 'App\Service\Pricing::total' candidate.php --next --format=json
 * ```
 *
 * With `--next` the result also says what to do next (`next.action`), so the skill needs no
 * `llm:context` call between targets: `retry` the same function after a rejection with attempts
 * left, `rewrite` the next unfinished target in the session order (with its `context`, the same as
 * `llm:context --format=json` prints), or `finish` when none is left.
 *
 * Exit codes: 0 — accepted; 1 — rejected (the reason is printed); 2 — no session, not a target,
 * no attempts left, a dirty git tree, invalid usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'apply-candidate',
    description: 'Apply a rewritten function proposed by the LLM stage and verify it',
)]
final class ApplyCandidate extends LlmStage
{
    public function configure(): void
    {
        parent::configure();
        $this->addArgument('function', InputArgument::REQUIRED, 'Key of the function, as `llm:targets` prints it');
        $this->addArgument('source', InputArgument::OPTIONAL, 'File with the new source of the function, `-` for stdin', '-');
        $this->addOption('allow-public-signatures', null, InputOption::VALUE_NONE, 'Allow native type changes of public overridable methods');
        $this->addOption('next', null, InputOption::VALUE_NONE, 'Also print what to do next: retry, the context of the next target, or finish');
        $this->addWithGitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);
        /** @var Schema\Llm $llm */
        $llm = $this->container->get(Schema\Llm::class);
        try {
            $format = $this->format($input);
            [$project, , $php] = $this->project();
            $session = $this->session($project, $input);
            $key = (string) $input->getArgument('function');
            $target = $session->target($key) ?? throw new \InvalidArgumentException(
                "`{$key}` is not a target of the session {$project->relative($session->runDir)}: see `opmin llm:targets`.",
            );
            $session->finished and throw new \InvalidArgumentException('The session is finished: start a new one with `opmin llm:targets`.');
            $done = \count($session->attempts($target->key));
            $done >= $llm->attemptsPerFunction and throw new \InvalidArgumentException(
                "No attempts left for `{$target->key}`: {$done} of llm.attempts_per_function = {$llm->attemptsPerFunction} are used.",
            );
            $source = $this->source((string) $input->getArgument('source'));
            $workspace = $this->workspace($project, $session->runDir, false, [$project->root->join($target->file)]);
        } catch (PhpBinaryException|\InvalidArgumentException|\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $number = $session->nextNumber();
        $candidate = $session->saveCandidate($number, $source);
        $file = $project->root->join($target->file);
        $optimizer = $this->optimizer($project, $php, $workspace, $output, (bool) $input->getOption('allow-public-signatures'));
        $optimizer->open([$file], null);
        $step = $optimizer->candidate($target->file, $target->key, $source);

        $accepted = $step->accepted !== [] && $step->error === null;
        $rejection = $step->rejected[0] ?? null;
        $before = $step->before[$target->file] ?? null;
        $attempt = new Attempt(
            number: $number,
            function: $target->key,
            file: $target->file,
            accepted: $accepted,
            gain: $step->gain(),
            reason: $accepted ? 'accepted' : ($step->error ?? ($rejection === null ? 'rejected' : $rejection['reason'])),
            candidate: $candidate,
            status: $step->accepted[0]['status'] ?? null,
            commit: $step->commit,
            before: $accepted && $before !== null ? $session->saveBefore($number, $before) : null,
            counterexample: $rejection['counterexample'] ?? null,
            checks: $accepted ? ($step->accepted[0]['checks'] ?? []) : null,
        );
        $session->record($attempt);
        $left = \max(0, $llm->attemptsPerFunction - \count($session->attempts($target->key)));

        $data = [
            'attempt' => $attempt->toArray(),
            # The counterexample is in the attempt already.
            'rejected' => \array_map(static fn(array $r): array => \array_diff_key($r, ['counterexample' => true]), $step->rejected),
            'attempts_left' => $left,
        ];
        [$next, $nextContext] = $input->getOption('next') === true
            ? $this->next($project, $php, $session, $target, $accepted, $left, $llm->attemptsPerFunction)
            : [null, null];
        $next === null or $data['next'] = $next;
        if ($format === 'json') {
            $output->write(self::json($data), false, OutputInterface::OUTPUT_RAW);
        } else {
            $output->writeln($accepted
                ? \sprintf('Accepted: -%d opcodes in %s (%s)%s.', $attempt->gain, $target->key, (string) $attempt->status, $attempt->commit === null ? '' : ', commit ' . \substr($attempt->commit, 0, 8))
                : \sprintf('Rejected: %s', $attempt->reason));
            if ($attempt->counterexample !== null) {
                $output->writeln('Counterexample input: ' . \json_encode($attempt->counterexample['input'] ?? null, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
                /** @var mixed $test */
                $test = $attempt->counterexample['test'] ?? null;
                \is_string($test) and $output->writeln('Counterexample test: ' . $test);
            }
            $output->writeln(\sprintf('Attempts left for %s: %d.', $target->key, $left));
            $next === null or $output->write(self::renderNext($next, $nextContext), false, OutputInterface::OUTPUT_RAW);
        }

        return $accepted ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $next
     */
    private static function renderNext(array $next, ?Context $context): string
    {
        return match ($next['action']) {
            'retry' => \sprintf("\nNext: retry %s with a different idea.\n", (string) $next['function']),
            'finish' => "\nNext: finish, no targets left: `opmin llm:finish`.\n",
            default => \sprintf("\nNext: rewrite %s.\n", (string) $next['function'])
                . ($context === null
                    ? \sprintf("Its context failed: %s\nRun `opmin llm:context` for it.\n", (string) ($next['error'] ?? ''))
                    : "\n" . $context->render()),
        };
    }

    /**
     * What the skill does after this attempt: `retry` the same function while it has attempts left
     * after a rejection (the reason is in the attempt, the source is unchanged), else `rewrite` the
     * next unfinished target with its context, or `finish` when none is left.
     *
     * @return array{array<string, mixed>, Context|null} The JSON of `next`, the context of the next target.
     */
    private function next(Project $project, PhpBinary $php, Session $session, Target $target, bool $accepted, int $left, int $allowed): array
    {
        if (!$accepted && $left > 0) {
            return [['action' => 'retry', 'function' => $target->key, 'attempts_left' => $left], null];
        }

        $next = $session->nextAfter($target, $allowed);
        if ($next === null) {
            return [['action' => 'finish'], null];
        }

        try {
            $context = $this->context($project, $php, $session, $next);
        } catch (PhpBinaryException|\RuntimeException $e) {
            return [['action' => 'rewrite', 'function' => $next->key, 'error' => $e->getMessage()], null];
        }

        return [['action' => 'rewrite', 'function' => $next->key, 'context' => $context->toArray()], $context];
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function source(string $argument): string
    {
        $source = $argument === '-' ? \stream_get_contents(\STDIN) : @\file_get_contents($argument);
        $source === false and throw new \InvalidArgumentException("Cannot read the candidate from `{$argument}`.");
        $source = \preg_replace('/^\s*<\?php\s*/', '', $source) ?? $source;
        \trim($source) === '' and throw new \InvalidArgumentException('The candidate is empty.');

        return $source;
    }
}
