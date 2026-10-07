<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use Opmin\Module\Analysis\IgnoreMarks;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Facts about the body of one function that every rule of opmin checks first: whether it may get a
 * new local variable, which names are taken, which variables are bound by reference, whether the
 * user asked to leave it alone.
 *
 * The function's own scope only: nested closures, functions and classes have scopes of their own
 * (but their names still count as taken — a closure's `use` and an arrow function see the parent).
 *
 * @internal
 */
final class FunctionBody
{
    /** Functions that read or write local variables by name. */
    private const SCOPE_FUNCTIONS = ['compact', 'extract', 'get_defined_vars', 'parse_str', 'mb_parse_str'];

    /**
     * Whether a new local variable cannot change the behavior: no `compact()`, `extract()`,
     * `get_defined_vars()`, `$$name`, `eval`, `include`, `goto` in the function's own scope.
     */
    public static function allowsNewVariables(FunctionLike $function): bool
    {
        foreach (self::ownNodes($function) as $node) {
            if ($node instanceof Expr\Eval_ || $node instanceof Expr\Include_ || $node instanceof Stmt\Goto_
                || $node instanceof Stmt\Label
                || ($node instanceof Expr\Variable && !\is_string($node->name))
                || ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name
                    && \in_array($node->name->getLast() === '' ? '' : \strtolower($node->name->getLast()), self::SCOPE_FUNCTIONS, true))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the user excluded the function from optimization for the rule `$alias`
     * ({@see IgnoreMarks}).
     */
    public static function ignored(FunctionLike $function, string $alias): bool
    {
        return IgnoreMarks::ignored($function, [$alias]);
    }

    /**
     * Every variable name used in the function, nested closures and arrow functions included.
     *
     * @return array<string, true>
     */
    public static function variableNames(FunctionLike $function): array
    {
        $names = ['this' => true, 'GLOBALS' => true];
        foreach ($function->getParams() as $param) {
            $param->var instanceof Expr\Variable && \is_string($param->var->name) and $names[$param->var->name] = true;
        }

        $function instanceof Expr\Closure and \array_map(static function (Node\ClosureUse $use) use (&$names): void {
            \is_string($use->var->name) and $names[$use->var->name] = true;
        }, $function->uses);
        foreach ((new NodeFinder())->findInstanceOf((array) $function->getStmts(), Expr\Variable::class) as $variable) {
            \is_string($variable->name) and $names[$variable->name] = true;
        }

        return $names;
    }

    /**
     * Variables of the function's own scope that are ever bound by reference: by-reference
     * parameters, `$a = &$b` on either side, `use (&$a)`, `foreach (… as &$a)`, `[&$a]`, `global`, `static`.
     * A call may change such a variable at any time.
     *
     * @return array<string, true>
     */
    public static function referencedVariables(FunctionLike $function): array
    {
        $names = [];
        $add = static function (?Node $var) use (&$names): void {
            $var instanceof Expr\Variable && \is_string($var->name) and $names[$var->name] = true;
        };
        foreach ($function->getParams() as $param) {
            $param->byRef and $add($param->var);
        }

        foreach (self::ownNodes($function) as $node) {
            match (true) {
                $node instanceof Expr\AssignRef => [$add($node->var), $add($node->expr)],
                $node instanceof Expr\Closure => \array_map(static fn(Node\ClosureUse $u) => $u->byRef ? $add($u->var) : null, $node->uses),
                $node instanceof Stmt\Foreach_ => $node->byRef ? $add($node->valueVar) : null,
                $node instanceof Node\ArrayItem => $node->byRef ? $add($node->value) : null,
                $node instanceof Stmt\Global_ => \array_map($add, $node->vars),
                $node instanceof Stmt\Static_ => \array_map(static fn(Node\StaticVar $v) => $add($v->var), $node->vars),
                default => null,
            };
        }

        return $names;
    }

    /**
     * A free variable name derived from `$base`: `$base`, then `$base2`, `$base3`…
     *
     * @param array<string, true> $taken
     * @return non-empty-string
     */
    public static function freeName(string $base, array $taken): string
    {
        $base = (string) \preg_replace('/[^A-Za-z0-9_\x80-\xff]/', '', $base);
        ($base === '' || \ctype_digit($base[0])) and $base = 'value' . $base;
        $name = $base;
        for ($i = 2; isset($taken[$name]); ++$i) {
            $name = $base . $i;
        }

        return $name;
    }

    /**
     * Nodes of the function's own scope: nested closures, functions and classes are not entered
     * (a closure's `use` list is part of this scope), arrow functions are (they share it).
     *
     * @return \Generator<Node>
     */
    public static function ownNodes(FunctionLike $function): \Generator
    {
        $stack = \array_reverse((array) $function->getStmts());
        while ($stack !== []) {
            $node = \array_pop($stack);
            yield $node;
            if ($node instanceof Expr\Closure) {
                \array_push($stack, ...\array_reverse($node->uses));
                continue;
            }

            if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike) {
                continue;
            }

            $children = [];
            foreach ($node->getSubNodeNames() as $name) {
                /** @var mixed $child */
                $child = $node->{$name};
                if ($child instanceof Node) {
                    $children[] = $child;
                } elseif (\is_array($child)) {
                    /** @var mixed $item */
                    foreach ($child as $item) {
                        $item instanceof Node and $children[] = $item;
                    }
                }
            }

            \array_push($stack, ...\array_reverse($children));
        }
    }
}
