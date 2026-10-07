<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Opcode\OpcodeCounter;
use Opmin\Module\Optimize\Rector\RectorRunner;
use Opmin\Module\Optimize\Rector\RuleSpec;
use Opmin\Module\Project\Project;

/**
 * `opmin check --suggest`: which Stage A rule would take the opcodes of a grown function back.
 *
 * A dry run that never touches the project: the files of the grown functions are copied to a
 * temporary directory, each rule is applied alone to fresh copies, and the copies are counted. The
 * rule that saves the most opcodes of a function is its suggestion. Nothing is verified — `opmin
 * optimize` proves a change before keeping it.
 *
 * @internal
 */
final readonly class Suggester
{
    /**
     * @param list<RuleSpec> $rules In the order of Stage A.
     * @param Path $tmpDir Where the copies are made (removed afterwards).
     * @param \Closure(string): void $log
     */
    public function __construct(
        private Project $project,
        private OpcodeCounter $counter,
        private RectorRunner $rector,
        private array $rules,
        private Path $tmpDir,
        private \Closure $log,
    ) {}

    /**
     * @param list<Finding> $grown
     * @return array<non-empty-string, Suggestion> By function key.
     */
    public function suggest(array $grown): array
    {
        $ops = $files = [];
        foreach ($grown as $finding) {
            $ops[$finding->key] = (int) $finding->after;
            $files[$finding->file] = true;
        }

        if ($ops === [] || $this->rules === []) {
            return [];
        }

        $dir = $this->tmpDir->join('suggest-' . \bin2hex(\random_bytes(4)));
        $copies = new Project($dir, false, null);
        $best = [];
        try {
            foreach ($this->rules as $rule) {
                ($this->log)("--suggest: {$rule->shortName()}");
                $paths = [];
                foreach (\array_keys($files) as $relative) {
                    $path = $dir->join($relative);
                    FS::mkdir((string) $path->parent());
                    \copy((string) $this->project->root->join($relative), (string) $path);
                    $paths[] = $path;
                }

                try {
                    $this->rector->run($rule, $paths);
                } catch (\RuntimeException $e) {
                    ($this->log)("--suggest: {$rule->shortName()} failed: {$e->getMessage()}");
                    continue;
                }

                foreach ($this->counter->count($copies, $paths)->functions as $function) {
                    $gain = ($ops[$function->key] ?? 0) - $function->opsOpt;
                    if (!isset($ops[$function->key]) || $gain <= 0 || $gain <= ($best[$function->key]->gain ?? 0)) {
                        continue;
                    }

                    $best[$function->key] = new Suggestion($rule->class, $gain);
                }
            }
        } finally {
            FS::remove($dir);
        }

        return $best;
    }
}
