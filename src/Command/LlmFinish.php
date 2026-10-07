<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Module\Llm\Attempt;
use Opmin\Module\Optimize\LlmCandidate;
use Opmin\Module\Optimize\StepReport;
use Opmin\Module\Optimize\Workspace;
use Opmin\Module\Php\PhpBinaryException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ends a session of the LLM stage: the full run of the project's tests (each attempt ran only the
 * tests of its function; while the full run fails, accepted attempts are taken back from the last
 * one), then `report.json` and `opmin.patch` in the run directory.
 *
 * Exit codes: 0 — done; 1 — the full test run fails even with every attempt taken back; 2 — no
 * session, a dirty git tree, invalid usage.
 *
 * @internal
 */
#[AsCommand(
    name: 'llm:finish',
    description: 'End the LLM session: the full test run, the report and the patch',
)]
final class LlmFinish extends LlmStage
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $style = new SymfonyStyle($input, $errorOutput);
        try {
            $format = $this->format($input);
            [$project, , $php] = $this->project();
            $session = $this->session($project, $input);
            $session->finished and throw new \InvalidArgumentException('The session is finished already.');
            $workspace = Workspace::create($project, $session->runDir, false, $this->ignoredPaths($project));
        } catch (PhpBinaryException|\InvalidArgumentException|\RuntimeException $e) {
            $style->error($e->getMessage());
            return Command::INVALID;
        }

        $attempts = $session->attempts();
        $files = [];
        foreach ($session->targets as $target) {
            $files[$target->file] = $project->root->join($target->file);
        }

        $optimizer = $this->optimizer($project, $php, $workspace, $output, false);
        [$environment, $warnings] = $this->environment($project, $php, $optimizer, $session->runDir);
        $report = $optimizer->open(\array_values($files), null, 'llm');
        foreach ($attempts as $attempt) {
            $step = new StepReport(LlmCandidate::class, $attempt->number);
            if ($attempt->accepted) {
                $step->accepted[] = [
                    'file' => $attempt->file,
                    'function' => $attempt->function,
                    'gain' => $attempt->gain,
                    'status' => (string) $attempt->status,
                    'checks' => $attempt->checks ?? [],
                ];
                $attempt->before === null or $step->before[$attempt->file] = (string) \file_get_contents((string) $session->runDir->join($attempt->before));
                $step->commit = $attempt->commit;
            } else {
                $step->reject($attempt->file, $attempt->function, $attempt->reason, $attempt->counterexample);
            }

            $report->steps[] = $step;
        }

        # The files are counted after the accepted attempts: the session started with their gains on top.
        $report->opsBefore += \array_sum(\array_map(static fn(Attempt $a): int => $a->accepted ? $a->gain : 0, $attempts));
        $optimizer->close($report);
        $patch = $workspace->finish();
        $report->patch = $patch === null ? null : $project->relative($patch);
        $report->environment = $environment;
        $report->warnings = $warnings;
        $data = $report->write([
            'run' => $project->relative($session->runDir),
            'attempts' => \array_map(static fn(Attempt $a): array => $a->toArray(), $attempts),
        ]);
        $session->markFinished();

        $kept = \array_filter($report->steps, static fn(StepReport $s): bool => $s->accepted !== []);
        $gain = \array_sum(\array_map(static fn(StepReport $s): int => $s->gain(), $kept));
        if ($format === 'json') {
            $output->write(self::json($data), false, OutputInterface::OUTPUT_RAW);
        } else {
            $out = new SymfonyStyle($input, $output);
            $report->notes === [] or $out->note($report->notes);
            $report->finalTests === false and $out->error('The full test run fails even with every attempt taken back: see report.json.');
            $out->success(\sprintf(
                '%d of %d attempt(s) kept, -%d opcodes. Report: %s%s',
                \count($kept),
                \count($attempts),
                $gain,
                $project->relative($session->runDir->join('report.md')),
                $report->patch === null ? '' : ', patch: ' . $report->patch,
            ));
        }

        return $report->finalTests === false ? Command::FAILURE : Command::SUCCESS;
    }
}
