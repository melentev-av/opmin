<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Optimize\Rector\RuleSpec;

/**
 * Where an `opmin optimize` run is, so `--resume` continues it after Ctrl+C or a crash (brief,
 * «Скорость самой тулзы»): `runs/<ts>/state.json` is written after every step.
 *
 * - `state.json` — the arguments of the run (files, rules, options), the position (pass, next rule,
 *   phase), the git HEAD after the last step, the steps so far, the optimizer's memory;
 * - `state/files/<path>` — the accepted content of every file a step changed;
 * - `state/steps/NNN.json` — the contents a step replaced, to take it back after the final test run.
 *
 * The files on disk can be anything after a crash (a candidate under test, a temporary file of an
 * atomic write); {@see self::restore()} brings them back to the accepted state.
 *
 * @internal
 */
final class RunState
{
    public const FILE = 'state.json';
    private const VERSION = 1;

    public int $pass = 1;

    /** Index of the next rule of the pass. */
    public int $rule = 0;

    /** The current pass kept a change. */
    public bool $improved = false;

    /** @var 'steps'|'closing'|'finished' */
    public string $phase = 'steps';

    /** HEAD after the last committed step (git mode). */
    public ?string $head = null;

    public int $opsBefore = 0;

    /** @var list<string> */
    public array $notes = [];

    /** @var array<string, mixed> What {@see Optimizer::memory()} returns. */
    public array $memory = [];

    /** @var list<StepReport> */
    public array $steps = [];

    /**
     * @param list<non-empty-string> $files Target files relative to the project root.
     * @param list<RuleSpec> $rules
     * @param array<string, mixed> $options CLI options of the run that the config does not hold.
     */
    private function __construct(
        public readonly Path $runDir,
        public readonly array $files,
        public readonly array $rules,
        public readonly array $options,
    ) {}

    /**
     * @param list<non-empty-string> $files
     * @param list<RuleSpec> $rules
     * @param array<string, mixed> $options
     */
    public static function start(Path $runDir, array $files, array $rules, array $options): self
    {
        return new self($runDir, $files, $rules, $options);
    }

    /**
     * @throws \RuntimeException When there is no state or it is not readable.
     */
    public static function load(Path $runDir): self
    {
        $file = $runDir->join(self::FILE);
        $file->exists() or throw new \RuntimeException("{$runDir} has no " . self::FILE . ': it is not a run of `opmin optimize`.');
        /** @var array{version?: int, files: list<non-empty-string>, rules: list<array{class: class-string}>, options: array<string, mixed>, pass: int, rule: int, improved: bool, phase: 'steps'|'closing'|'finished', head: ?string, ops_before: int, notes: list<string>, memory: array<string, mixed>, steps: list<array<string, mixed>>} $data */
        $data = \json_decode((string) \file_get_contents((string) $file), true, 512, \JSON_THROW_ON_ERROR);
        ($data['version'] ?? 0) === self::VERSION or throw new \RuntimeException(
            "{$file} was written by another version of opmin: start a new run.",
        );

        $state = new self($runDir, $data['files'], \array_map(RuleSpec::fromArray(...), $data['rules']), $data['options']);
        $state->pass = $data['pass'];
        $state->rule = $data['rule'];
        $state->improved = $data['improved'];
        $state->phase = $data['phase'];
        $state->head = $data['head'];
        $state->opsBefore = $data['ops_before'];
        $state->notes = $data['notes'];
        $state->memory = $data['memory'];
        foreach ($data['steps'] as $i => $step) {
            $before = $runDir->join('state', 'steps', self::number($i) . '.json');
            /** @var array<non-empty-string, string> $contents */
            $contents = $before->exists() ? \json_decode((string) \file_get_contents((string) $before), true, 512, \JSON_THROW_ON_ERROR) : [];
            $state->steps[] = StepReport::fromArray($step, $contents);
        }

        return $state;
    }

    /**
     * The newest run under `$runsDir` that did not finish.
     */
    public static function latest(Path $runsDir): ?Path
    {
        $files = \glob((string) $runsDir->join('*', self::FILE));
        $files === false and $files = [];
        \rsort($files, \SORT_STRING);
        foreach ($files as $file) {
            /** @var mixed $data */
            $data = \json_decode((string) \file_get_contents($file), true);
            if (\is_array($data) && ($data['phase'] ?? null) !== 'finished') {
                return Path::create(\dirname($file));
            }
        }

        return null;
    }

    public function save(): void
    {
        $steps = [];
        foreach ($this->steps as $i => $step) {
            $before = $this->runDir->join('state', 'steps', self::number($i) . '.json');
            if ($step->before !== [] && !$before->exists()) {
                FS::mkdir((string) $before->parent());
                FS::replace($before, \json_encode($step->before, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
            }

            $steps[] = $step->toArray();
        }

        FS::replace($this->runDir->join(self::FILE), \json_encode([
            'version' => self::VERSION,
            'files' => $this->files,
            'rules' => \array_map(static fn(RuleSpec $r): array => $r->toArray(), $this->rules),
            'options' => $this->options,
            'pass' => $this->pass,
            'rule' => $this->rule,
            'improved' => $this->improved,
            'phase' => $this->phase,
            'head' => $this->head,
            'ops_before' => $this->opsBefore,
            'notes' => $this->notes,
            'memory' => $this->memory,
            'steps' => $steps,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * Remembers the accepted content of a file a step changed.
     *
     * @param non-empty-string $relative
     */
    public function keep(string $relative, string $content): void
    {
        $file = $this->runDir->join('state', 'files', $relative);
        FS::mkdir((string) $file->parent());
        FS::replace($file, $content);
    }

    /**
     * Writes every target file as the last saved state has it: the kept content of a changed file,
     * the original of the others; temporary files of interrupted atomic writes are removed.
     *
     * @return list<non-empty-string> Files that had to be rewritten.
     * @throws \RuntimeException When a file has neither a kept content nor an original.
     */
    public function restore(Path $root): array
    {
        $rewritten = [];
        foreach ($this->files as $relative) {
            $path = $root->join($relative);
            $tmps = \glob((string) $path . '.opmin-*.tmp');
            foreach ($tmps === false ? [] : $tmps as $tmp) {
                @\unlink($tmp);
            }

            $kept = $this->runDir->join('state', 'files', $relative);
            $original = $this->runDir->join('original', $relative);
            $source = $kept->exists() ? $kept : ($original->exists() ? $original : null);
            $source === null and throw new \RuntimeException("Neither the kept content nor the original of {$relative} is in {$this->runDir}.");
            $content = (string) \file_get_contents((string) $source);
            if ((string) @\file_get_contents((string) $path) !== $content) {
                FS::replace($path, $content);
                $rewritten[] = $relative;
            }
        }

        return $rewritten;
    }

    private static function number(int $i): string
    {
        return \str_pad((string) ($i + 1), 3, '0', \STR_PAD_LEFT);
    }
}
