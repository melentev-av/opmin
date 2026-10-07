<?php

declare(strict_types=1);

namespace Opmin\Module\Llm;

use Internal\Path;
use Opmin\Module\Analysis\Flag;
use Opmin\Module\Analysis\IgnoreMarks;
use Opmin\Module\Analysis\Restriction;
use Opmin\Module\Config\Schema;
use Opmin\Module\Opcode\CountResult;
use Opmin\Module\Optimize\LlmCandidate;
use Opmin\Module\Optimize\Review\Declined;
use Opmin\Module\Optimize\Units;
use Opmin\Module\Project\Project;

/**
 * The functions the LLM stage rewrites: the top-level functions with the most opcodes (with their
 * closures, which are rewritten together with them), `llm.top_n` of them.
 *
 * Left out: main code, functions that are never changed (`eval`, `include`, line numbers) and the
 * ones the user excluded for the LLM stage (`ignore.functions`, `#[\Opmin\Ignore]`, `@opmin-ignore`,
 * `{function, rule: llm}` declined in the review).
 *
 * @internal
 */
final readonly class TargetSelector
{
    public function __construct(
        private Project $project,
        private Schema\Ignore $ignore,
        private Declined $declined,
    ) {}

    /**
     * @param positive-int $topN
     * @return list<Target> Most opcodes first.
     */
    public function select(CountResult $counts, int $topN): array
    {
        $units = [];
        $families = [];
        foreach ($counts->functions as $function) {
            if (!$function->optimizable) {
                continue;
            }

            $units[$function->file] ??= Units::of((string) @\file_get_contents((string) $this->project->root->join($function->file)), $function->file);
            $top = $units[$function->file]->topLevel($function->key);
            if ($top === null) {
                continue;
            }

            $families[$top] ??= ['file' => $function->file, 'line' => $function->line, 'ops' => 0, 'excluded' => false];
            $families[$top]['ops'] += $function->opsOpt;
            $restrictions = Flag::restrictionsOf(Flag::fromValues($function->flags));
            if (\in_array(Restriction::Skip, $restrictions, true) || \in_array(Restriction::LineSensitive, $restrictions, true)) {
                $families[$top]['excluded'] = true;
            }
        }

        $targets = [];
        foreach ($families as $key => $family) {
            if ($family['excluded'] || $this->ignored($key, $units[$family['file']])) {
                continue;
            }

            $targets[] = new Target($key, $family['file'], $family['line'], $family['ops']);
        }

        \usort($targets, static fn(Target $a, Target $b): int => [$b->opsOpt, $a->key] <=> [$a->opsOpt, $b->key]);

        return \array_slice($targets, 0, $topN);
    }

    /**
     * Relative paths of the files a session needs: those of its targets.
     *
     * @param list<Target> $targets
     * @return list<Path>
     */
    public function files(array $targets): array
    {
        $files = [];
        foreach ($targets as $target) {
            $files[$target->file] = $this->project->root->join($target->file);
        }

        return \array_values($files);
    }

    /**
     * @param non-empty-string $key
     */
    private function ignored(string $key, Units $units): bool
    {
        $unit = $units->units[$key];
        $names = [LlmCandidate::alias()];

        return IgnoreMarks::byConfig($this->ignore->functions, $key)
            || $this->declined->has($key, [...$names, LlmCandidate::class])
            || ($unit->node !== null && IgnoreMarks::ignored($unit->node, $names))
            || ($unit->class !== null && IgnoreMarks::ignored($unit->class, $names));
    }
}
