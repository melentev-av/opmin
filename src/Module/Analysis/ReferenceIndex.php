<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Project\FileFinder;
use Opmin\Module\Project\Project;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Project-wide index of dynamic references ({@see FileReferences}): gives a function the flags it
 * gets from other files — {@see Flag::CalledDynamically}, {@see Flag::Reflection}.
 *
 * Scans every `*.php` of the project except `vendor/` (tests and routes call application code by
 * name too). Per-file results are cached by content in `cache.dir/refs/`.
 *
 * @internal
 */
final class ReferenceIndex
{
    /** Bump when the collected data changes. */
    private const FORMAT = 1;

    /** Directories never scanned. */
    private const SKIP = ['vendor', 'node_modules', '.git', 'runs', 'playground'];

    /** @var array<lowercase-string, true> */
    private array $functions = [];

    /** @var array<lowercase-string, true> */
    private array $methods = [];

    private bool $dynamicFunctionCalls = false;

    /** @var array<lowercase-string, true> */
    private array $dynamicMethodScopes = [];

    /** @var array<lowercase-string, true> */
    private array $reflectedClasses = [];

    private readonly Parser $parser;

    /**
     * @param Path|null $cacheDir `cache.dir`; null — no cache.
     */
    public function __construct(
        private readonly ?Path $cacheDir = null,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Scans the project.
     */
    public static function build(Project $project, ?Path $cacheDir): self
    {
        $index = new self($cacheDir);
        $skip = self::SKIP;
        $cacheDir === null or $cacheDir->isWithin($project->root) and $skip[] = $project->relative($cacheDir);
        foreach ((new FileFinder())->find($project, [$project->root], $skip) as $file) {
            $content = @\file_get_contents((string) $file);
            $content === false or $index->add($project->relative($file), $content);
        }

        return $index;
    }

    /**
     * @param non-empty-string $file Path relative to the project root (part of the cache key).
     */
    public function add(string $file, string $content): void
    {
        $references = $this->cached($file, $content);
        foreach ($references->functions as $name) {
            $this->functions[$name] = true;
        }

        foreach ($references->methods as $name) {
            $this->methods[$name] = true;
        }

        foreach ($references->dynamicMethodScopes as $name) {
            $this->dynamicMethodScopes[$name] = true;
        }

        foreach ($references->reflectedClasses as $name) {
            $this->reflectedClasses[$name] = true;
        }

        $this->dynamicFunctionCalls = $this->dynamicFunctionCalls || $references->dynamicFunctionCalls;
    }

    /**
     * Flags of a function from the rest of the project.
     *
     * @param non-empty-string $key Function key (`App\fn`, `App\Foo::bar`, `App\fn::{class:1}::m`).
     * @return list<Flag>
     */
    public function flagsFor(string $key, UnitKind $kind): array
    {
        $key = \strtolower((string) \preg_replace('/#.*$/', '', $key));

        return match ($kind) {
            UnitKind::Function => $this->dynamicFunctionCalls || isset($this->functions[$key]) ? [Flag::CalledDynamically] : [],
            UnitKind::Method => $this->methodFlags($key),
            default => [],
        };
    }

    /**
     * The functions with the flags from the rest of the project added.
     *
     * @param list<FunctionCount> $functions
     * @return list<FunctionCount>
     */
    public function apply(array $functions): array
    {
        return \array_map(function (FunctionCount $function): FunctionCount {
            $extra = $this->flagsFor($function->key, $function->kind);
            if ($extra === []) {
                return $function;
            }

            return $function->withFlags(Flag::values([...Flag::fromValues($function->flags), ...$extra]));
        }, $functions);
    }

    /**
     * @param lowercase-string $key
     * @return list<Flag>
     */
    private function methodFlags(string $key): array
    {
        $at = \strrpos($key, '::');
        if ($at === false) {
            return [];
        }

        $class = \substr($key, 0, $at);
        $method = \substr($key, $at + 2);
        # A method of an anonymous class: its class cannot be named.
        $anonymous = \str_contains($class, '::{');
        $flags = [];
        if (isset($this->methods[FileReferences::ANY_CLASS . '::' . $method])
            || (!$anonymous && isset($this->methods[$class . '::' . $method]))
            || isset($this->dynamicMethodScopes[FileReferences::ANY_CLASS])
            || (!$anonymous && isset($this->dynamicMethodScopes[$class]))
        ) {
            $flags[] = Flag::CalledDynamically;
        }

        if (isset($this->reflectedClasses[FileReferences::ANY_CLASS]) || (!$anonymous && isset($this->reflectedClasses[$class]))) {
            $flags[] = Flag::Reflection;
        }

        return $flags;
    }

    /**
     * @param non-empty-string $file
     */
    private function cached(string $file, string $content): FileReferences
    {
        $key = \hash('sha256', \serialize([self::FORMAT, $file, \hash('sha256', $content), Info::version()]));
        $path = $this->cacheDir?->join('refs', \substr($key, 0, 2), $key . '.json');
        if ($path !== null) {
            $raw = @\file_get_contents((string) $path);
            /** @var mixed $data */
            $data = $raw === false ? null : \json_decode($raw, true);
            if (\is_array($data)) {
                try {
                    return FileReferences::fromArray($data);
                } catch (\Throwable) {
                    # A foreign or broken entry is a miss.
                }
            }
        }

        try {
            /** @var array<array-key, Node> $stmts */
            $stmts = $this->parser->parse($content) ?? [];
        } catch (Error) {
            # Not valid PHP (a template, newer syntax): it cannot call anything we know of.
            return new FileReferences();
        }

        $references = (new ReferenceCollector())->collect($stmts);
        # A name that is not UTF-8 (Latin-1 bytes are valid in PHP names) cannot be JSON: such a file is
        # simply not cached — substituting the bytes would lose a reference.
        $json = $path === null ? false : \json_encode($references->toArray(), \JSON_UNESCAPED_SLASHES);
        if ($path !== null && $json !== false) {
            FS::mkdir((string) $path->parent());
            $tmp = (string) $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
            \file_put_contents($tmp, $json);
            \rename($tmp, (string) $path);
        }

        return $references;
    }
}
