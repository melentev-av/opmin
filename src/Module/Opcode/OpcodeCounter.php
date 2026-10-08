<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode;

use Internal\Path;
use Opmin\Module\Opcode\Dump\DumpBlock;
use Opmin\Module\Opcode\Dump\DumpParseException;
use Opmin\Module\Opcode\Dump\DumpParser;
use Opmin\Module\Opcode\Dump\FileDump;
use Opmin\Module\Opcode\Dump\OpcacheDumper;
use Opmin\Module\Opcode\Dump\Phase;
use Opmin\Module\Opcode\Locate\DumpMatcher;
use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Opcode\Locate\LocateException;
use Opmin\Module\Opcode\Locate\MatchException;
use Opmin\Module\Project\Project;

/**
 * Counts opcodes per function of the given files: cache → OPcache dump → parse → match with the AST.
 *
 * ```php
 * $result = $counter->count($project, $files);
 * ```
 *
 * @internal
 */
final class OpcodeCounter
{
    public function __construct(
        private readonly OpcacheDumper $dumper,
        private readonly CountCache $cache,
        private readonly FunctionLocator $locator = new FunctionLocator(),
        private readonly DumpParser $parser = new DumpParser(),
        private readonly DumpMatcher $matcher = new DumpMatcher(),
    ) {}

    /**
     * @param list<Path> $files Absolute.
     * @param \Closure(int<0, max> $done, int<0, max> $total, non-empty-string $file): void|null $progress
     *        Called after each file.
     */
    public function count(Project $project, array $files, ?\Closure $progress = null): CountResult
    {
        $total = \count($files);
        $done = 0;
        /** @var array<non-empty-string, list<FunctionCount>> $counted */
        $counted = [];
        $errors = [];
        $cached = 0;
        /** @var array<non-empty-string, array{non-empty-string, string, non-empty-string}> $pending Absolute => [relative, content, cache key]. */
        $pending = [];
        foreach ($files as $file) {
            $relative = $project->relative($file);
            $content = @\file_get_contents((string) $file);
            if ($content === false) {
                $errors[$relative] = 'Cannot read the file.';
                $progress === null or $progress(++$done, $total, $relative);
                continue;
            }

            $key = $this->cache->key($relative, $content);
            $hit = $this->cache->get($key);
            if ($hit !== null) {
                $counted[$relative] = $hit;
                ++$cached;
                $progress === null or $progress(++$done, $total, $relative);
                continue;
            }

            $pending[(string) $file] = [$relative, $content, $key];
        }

        $this->dumper->dump(\array_keys($pending), function (FileDump $dump) use (&$counted, &$errors, &$done, $pending, $total, $progress): void {
            [$relative, $content, $key] = $pending[$dump->file];
            try {
                $dump->error === null or throw new \RuntimeException($dump->error);
                $blocks = $this->parser->parse($dump->dump);
                $blocks === [] and throw new \RuntimeException(
                    'OPcache compiled the file but did not dump it (is it in opcache.blacklist_filename?).',
                );
                $functions = $this->matcher->match($this->locator->locate($content, $relative . '::<main>'), $blocks, $relative);
                $this->cache->set($key, $functions);
                $counted[$relative] = $functions;
            } catch (DumpParseException|LocateException|MatchException $e) {
                $errors[$relative] = 'Cannot attribute opcodes to functions: ' . $e->getMessage();
            } catch (\RuntimeException $e) {
                $errors[$relative] = $e->getMessage() ?: 'Compilation failed';
            }

            $progress === null or $progress(++$done, $total, $relative);
        });

        \ksort($counted, \SORT_STRING);
        \ksort($errors, \SORT_STRING);

        return new CountResult(
            functions: \array_merge(...\array_values($counted)),
            errors: $errors,
            files: \count($counted),
            cached: $cached,
        );
    }

    /**
     * The optimized opcode listing of every function of one file, as OPcache dumps it (never cached:
     * only the LLM stage needs it, for one function at a time).
     *
     * @return array<non-empty-string, string> Key => listing.
     * @throws \RuntimeException When the file cannot be compiled or attributed.
     */
    public function listings(Project $project, Path $file): array
    {
        $relative = $project->relative($file);
        $content = (string) @\file_get_contents((string) $file);
        $result = null;
        $this->dumper->dump([(string) $file], static function (FileDump $dump) use (&$result): void {
            $result = $dump;
        });
        /** @var FileDump|null $result */
        $result === null || $result->error !== null and throw new \RuntimeException($result?->error ?? 'Compilation failed');

        try {
            $blocks = $this->matcher->blocks($this->locator->locate($content, $relative . '::<main>'), $this->parser->parse($result->dump), Phase::Opt);
        } catch (DumpParseException|LocateException|MatchException $e) {
            throw new \RuntimeException('Cannot attribute opcodes to functions: ' . $e->getMessage(), previous: $e);
        }

        return \array_map(static fn(DumpBlock $block): string => $block->listing, $blocks);
    }
}
