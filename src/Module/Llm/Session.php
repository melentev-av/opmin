<?php

declare(strict_types=1);

namespace Opmin\Module\Llm;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;

/**
 * One pass of the LLM stage (brief, «Stage B»): a run directory `runs/<ts>/` that the separate calls
 * of the skill share — `llm:targets` starts it, `llm:context` and `apply-candidate` work in it,
 * `llm:finish` ends it.
 *
 * - `llm.json` — the targets, chosen once;
 * - `attempts.jsonl` — every attempt with its verdict, so a rejected idea is not tried again and
 *   `llm.attempts_per_function` holds across calls;
 * - `candidates/NNN.php` — the source of every attempt, `steps/NNN.before` — the file before an
 *   accepted one (to take it back when the full test run fails at the end).
 *
 * @internal
 */
final class Session
{
    public const MARKER = 'llm.json';
    private const ATTEMPTS = 'attempts.jsonl';

    /**
     * @param list<Target> $targets
     */
    private function __construct(
        public readonly Path $runDir,
        public readonly array $targets,
        public readonly bool $finished,
    ) {}

    /**
     * @param list<Target> $targets
     */
    public static function start(Path $runDir, array $targets, string $phpVersion): self
    {
        FS::mkdir((string) $runDir);
        self::writeJson($runDir->join(self::MARKER), [
            'php' => $phpVersion,
            'started' => \date(\DATE_ATOM),
            'finished' => null,
            'targets' => \array_map(static fn(Target $t): array => $t->toArray(), $targets),
        ]);

        return new self($runDir, $targets, false);
    }

    /**
     * The session in `$runDir`, or the latest one under `$runsDir` when `$runDir` is null.
     *
     * @throws \RuntimeException When there is none.
     */
    public static function open(Path $runsDir, ?Path $runDir = null): self
    {
        if ($runDir === null) {
            $markers = \glob((string) $runsDir->join('*', self::MARKER));
            $markers === false and $markers = [];
            \sort($markers, \SORT_STRING);
            $last = \end($markers);
            $last === false and throw new \RuntimeException('No LLM session: start one with `opmin llm:targets`.');
            $runDir = Path::create(\dirname($last));
        }

        $marker = $runDir->join(self::MARKER);
        $marker->exists() or throw new \RuntimeException("{$runDir} is not an LLM session: no " . self::MARKER . '.');
        /** @var array{targets?: list<array<string, mixed>>, finished?: string|null} $data */
        $data = \json_decode((string) \file_get_contents((string) $marker), true, 512, \JSON_THROW_ON_ERROR);

        return new self(
            $runDir,
            \array_map(Target::fromArray(...), $data['targets'] ?? []),
            ($data['finished'] ?? null) !== null,
        );
    }

    public function target(string $key): ?Target
    {
        $key = \ltrim($key, '\\');
        foreach ($this->targets as $target) {
            if (\strcasecmp($target->key, $key) === 0) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Attempts so far, of one function or of all.
     *
     * @return list<Attempt>
     */
    public function attempts(?string $key = null): array
    {
        $file = $this->runDir->join(self::ATTEMPTS);
        if (!$file->exists()) {
            return [];
        }

        $attempts = [];
        foreach (\explode("\n", (string) \file_get_contents((string) $file)) as $line) {
            if (\trim($line) === '') {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $attempt = Attempt::fromArray($data);
            $key === null || \strcasecmp($attempt->function, \ltrim($key, '\\')) === 0 and $attempts[] = $attempt;
        }

        return $attempts;
    }

    /**
     * Number of the next attempt: the source of the candidate goes to `candidates/NNN.php`.
     *
     * @return positive-int
     */
    public function nextNumber(): int
    {
        return \count($this->attempts()) + 1;
    }

    /**
     * Saves a candidate source, before it is judged.
     *
     * @param positive-int $number
     * @return non-empty-string Path relative to the run directory.
     */
    public function saveCandidate(int $number, string $source): string
    {
        $relative = 'candidates/' . \str_pad((string) $number, 3, '0', \STR_PAD_LEFT) . '.php';
        FS::mkdir((string) $this->runDir->join('candidates'));
        \file_put_contents((string) $this->runDir->join($relative), $source);

        return $relative;
    }

    /**
     * Saves the content of a file before an accepted attempt.
     *
     * @param positive-int $number
     * @return non-empty-string Path relative to the run directory.
     */
    public function saveBefore(int $number, string $content): string
    {
        $relative = 'steps/' . \str_pad((string) $number, 3, '0', \STR_PAD_LEFT) . '.before';
        FS::mkdir((string) $this->runDir->join('steps'));
        \file_put_contents((string) $this->runDir->join($relative), $content);

        return $relative;
    }

    public function record(Attempt $attempt): void
    {
        \file_put_contents(
            (string) $this->runDir->join(self::ATTEMPTS),
            \json_encode($attempt->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n",
            \FILE_APPEND,
        );
    }

    public function markFinished(): void
    {
        $marker = $this->runDir->join(self::MARKER);
        /** @var array<string, mixed> $data */
        $data = \json_decode((string) \file_get_contents((string) $marker), true, 512, \JSON_THROW_ON_ERROR);
        $data['finished'] = \date(\DATE_ATOM);
        self::writeJson($marker, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeJson(Path $file, array $data): void
    {
        \file_put_contents(
            (string) $file,
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
