<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Locate;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Finds the function-like units of a file and lists them in the order OPcache dumps them.
 *
 * The dump order is that of `zend_foreach_op_array()` (PHP 8.1–8.5):
 *  1. `$_main`, then its dynamic function definitions;
 *  2. top-level functions in source order, each followed by its dynamic definitions;
 *  3. classes in the order their compilation completes (a class nested in a method — e.g. an
 *     anonymous one — completes before the outer class), each with its methods in declaration order
 *     followed by the property hooks (properties in declaration order, `get` before `set`); every
 *     method and hook is followed by its dynamic definitions.
 * Dynamic definitions of an op_array are the closures, arrow functions and non-top-level named
 * functions in its body, in source order, each followed recursively by its own.
 *
 * Closures and anonymous classes are anonymous in the dump (and their names change between PHP
 * versions), so they get stable keys here: the parent's key plus an ordinal within the parent —
 * never a line number, which shifts with any edit above.
 *
 * @internal
 */
final class FunctionLocator
{
    private readonly Parser $parser;

    /** @var array<string, int<0, max>> Closures per parent key. */
    private array $closures = [];

    /** @var array<string, int<0, max>> Anonymous classes per parent key. */
    private array $anonymousClasses = [];

    /** @var array<string, true> */
    private array $keys = [];

    /** @var list<CodeUnit> Methods and hooks of completed classes, in dump order. */
    private array $classUnits = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param non-empty-string $mainKey Key of the file's main code (`src/routes.php::<main>`).
     * @return list<CodeUnit> Units in dump order, including abstract methods (see {@see CodeUnit::$abstract}).
     * @throws LocateException On a syntax error.
     */
    public function locate(string $code, string $mainKey): array
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error $e) {
            throw new LocateException('Syntax error: ' . $e->getMessage(), previous: $e);
        }

        /** @var list<Node> $stmts */
        $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);
        $this->closures = $this->anonymousClasses = $this->keys = $this->classUnits = [];
        $this->keys[$mainKey] = true;

        $main = [new CodeUnit($mainKey, UnitKind::Main, '$_main', 1, 1)];
        $functions = [];
        # One pass in source order: classes complete in the same order as when PHP compiles the file.
        foreach ($this->topLevel($stmts) as $stmt) {
            if ($stmt instanceof Stmt\Function_) {
                \array_push($functions, ...$this->function($stmt));
                continue;
            }

            \array_push($main, ...$this->scan($stmt, $mainKey));
        }

        return [...$main, ...$functions, ...$this->classUnits];
    }

    /**
     * Statements of the file, with the bodies of `namespace X { }` blocks inlined.
     *
     * @param list<Node> $stmts
     * @return list<Node>
     */
    private function topLevel(array $stmts): array
    {
        $result = [];
        foreach ($stmts as $stmt) {
            $stmt instanceof Stmt\Namespace_
                ? \array_push($result, ...$stmt->stmts)
                : $result[] = $stmt;
        }

        return $result;
    }

    /**
     * Dynamic definitions found in a subtree, in pre-order; classes go to {@see self::$classUnits}.
     * Property hooks (also of promoted parameters) are skipped: they are op_arrays of the class.
     *
     * @param Node|array<array-key, mixed>|mixed $node
     * @return list<CodeUnit>
     */
    private function scan(mixed $node, string $parentKey): array
    {
        if (\is_array($node)) {
            $result = [];
            /** @var mixed $child */
            foreach ($node as $child) {
                \array_push($result, ...$this->scan($child, $parentKey));
            }

            return $result;
        }

        return match (true) {
            !$node instanceof Node => [],
            $node instanceof Closure, $node instanceof ArrowFunction => $this->closure($node, $parentKey),
            $node instanceof Stmt\Function_ => $this->function($node),
            $node instanceof Stmt\ClassLike => $this->classLike($node, $parentKey),
            default => $this->scan(\array_map(
                static fn(string $name): mixed => $node->{$name},
                \array_diff($node->getSubNodeNames(), ['attrGroups', 'hooks']),
            ), $parentKey),
        };
    }

    /**
     * @return list<CodeUnit>
     */
    private function closure(Closure|ArrowFunction $node, string $parentKey): array
    {
        $n = $this->closures[$parentKey] = ($this->closures[$parentKey] ?? 0) + 1;
        $key = $this->unique("{$parentKey}::{closure:{$n}}");

        return [
            new CodeUnit($key, UnitKind::Closure, null, $node->getStartLine(), $node->getEndLine()),
            ...$this->scan($node instanceof Closure ? [$node->params, $node->stmts] : [$node->params, $node->expr], $key),
        ];
    }

    /**
     * @return list<CodeUnit>
     */
    private function function(Stmt\Function_ $node): array
    {
        /** @var non-empty-string $name */
        $name = (string) $node->namespacedName;
        $key = $this->unique($name);

        return [
            new CodeUnit($key, UnitKind::Function, $name, $node->getStartLine(), $node->getEndLine()),
            ...$this->scan([$node->params, $node->stmts], $key),
        ];
    }

    /**
     * Collects the methods and hooks of a class into {@see self::$classUnits} after everything
     * nested in it, and returns nothing: they are not dynamic definitions of the parent.
     *
     * @return list<never>
     */
    private function classLike(Stmt\ClassLike $node, string $parentKey): array
    {
        if ($node->name === null) {
            $n = $this->anonymousClasses[$parentKey] = ($this->anonymousClasses[$parentKey] ?? 0) + 1;
            $classKey = "{$parentKey}::{class:{$n}}";
            $dumpClass = '@anonymous';
        } else {
            $classKey = $dumpClass = (string) $node->namespacedName;
        }

        $interface = $node instanceof Stmt\Interface_;
        $methods = [];
        /** @var list<array{non-empty-string, list<Node\PropertyHook>}> $hooked Properties with hooks. */
        $hooked = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod) {
                $name = $stmt->name->toString();
                $key = $this->unique("{$classKey}::{$name}");
                $methods[] = new CodeUnit(
                    $key,
                    UnitKind::Method,
                    "{$dumpClass}::{$name}",
                    $stmt->getStartLine(),
                    $stmt->getEndLine(),
                    abstract: $interface || $stmt->stmts === null,
                );
                \array_push($methods, ...$this->scan([$stmt->params, $stmt->stmts], $key));
                foreach ($stmt->params as $param) {
                    # Promoted properties are declared while the constructor compiles.
                    $param->hooks === [] or $hooked[] = [$this->varName($param), $param->hooks];
                }
            } elseif ($stmt instanceof Stmt\Property && $stmt->hooks !== []) {
                $hooked[] = [$stmt->props[0]->name->toString(), $stmt->hooks];
            }
        }

        $hooks = [];
        foreach ($hooked as [$property, $propertyHooks]) {
            \usort($propertyHooks, static fn(Node\PropertyHook $a, Node\PropertyHook $b): int => $a->name->toLowerString() === 'get' ? -1 : ($b->name->toLowerString() === 'get' ? 1 : 0));
            foreach ($propertyHooks as $hook) {
                $name = $hook->name->toLowerString();
                $key = $this->unique("{$classKey}::\${$property}::{$name}");
                $hooks[] = new CodeUnit(
                    $key,
                    UnitKind::Hook,
                    "{$dumpClass}::\${$property}::{$name}",
                    $hook->getStartLine(),
                    $hook->getEndLine(),
                    abstract: $hook->body === null,
                );
                \array_push($hooks, ...$this->scan([$hook->params, $hook->body], $key));
            }
        }

        \array_push($this->classUnits, ...$methods, ...$hooks);

        return [];
    }

    /**
     * @return non-empty-string
     */
    private function varName(Node\Param $param): string
    {
        $var = $param->var;
        $var instanceof Node\Expr\Variable && \is_string($var->name) && $var->name !== ''
            or throw new LocateException('Promoted property without a plain variable name.');

        return $var->name;
    }

    /**
     * Two units can share a name only in code like `if (...) { function f() {} } else { function f() {} }`.
     *
     * @param non-empty-string $key
     * @return non-empty-string
     */
    private function unique(string $key): string
    {
        $candidate = $key;
        for ($n = 2; isset($this->keys[$candidate]); ++$n) {
            $candidate = "{$key}#{$n}";
        }

        $this->keys[$candidate] = true;

        return $candidate;
    }
}
