<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Coverage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Branches of a function that no input can reach, by PHPStan's report on the original file (brief
 * 2.4): they are left out of the coverage the differential test must reach.
 *
 * PHPStan names a line, not a column, so an error counts only when it points at one thing without
 * doubt: a decision of its kind alone on the line, or the whole condition of a decision being the
 * only expression of the reported kind on the line (`is_int($x)` in `if (is_int($x))`, not in
 * `if ($a && is_int($x))`). Anything less certain leaves the branch counted: a branch wrongly taken
 * for dead would let an untested change through, one wrongly kept only costs coverage.
 *
 * @psalm-type Error = array{file: string, message: string, identifier: string, line: int}
 * @internal
 */
final class DeadBranches
{
    /** Errors about a whole decision: identifier prefix => decision kind. */
    private const DECISIONS = [
        'if' => 'if',
        'elseif' => 'elseif',
        'while' => 'while',
        'ternary' => 'ternary',
        'booleanAnd' => 'and',
        'logicalAnd' => 'and',
        'booleanOr' => 'or',
        'logicalOr' => 'or',
        'match' => 'arm',
    ];

    /** Errors about an expression that is always true or false: identifier prefix => node classes. */
    private const CHECKS = [
        'function' => [Expr\FuncCall::class],
        'method' => [Expr\MethodCall::class, Expr\NullsafeMethodCall::class],
        'staticMethod' => [Expr\StaticCall::class],
        'instanceof' => [Expr\Instanceof_::class],
        'identical' => [Expr\BinaryOp\Identical::class],
        'notIdentical' => [Expr\BinaryOp\NotIdentical::class],
        'equal' => [Expr\BinaryOp\Equal::class],
        'notEqual' => [Expr\BinaryOp\NotEqual::class],
        'greater' => [Expr\BinaryOp\Greater::class],
        'greaterOrEqual' => [Expr\BinaryOp\GreaterOrEqual::class],
        'smaller' => [Expr\BinaryOp\Smaller::class],
        'smallerOrEqual' => [Expr\BinaryOp\SmallerOrEqual::class],
        'booleanNot' => [Expr\BooleanNot::class],
    ];

    /** @var list<array{kind: string, node: Node, cond: Expr|null, true: list<Dead>, false: list<Dead>}> */
    private array $decisions = [];

    /** @var list<array{Node, list<Dead>}> Statements after which nothing runs: the statement => what is dead. */
    private array $unreachable = [];

    /** @var list<Node> Every node of the function (nested functions and classes excluded). */
    private array $nodes = [];

    /** @var list<Dead> */
    private array $dead = [];

    private function __construct() {}

    /**
     * @param Node\FunctionLike $function Node of the original version (positions in its file).
     * @param list<Error> $errors PHPStan errors of the original file.
     */
    public static function find(Node\FunctionLike $function, array $errors): self
    {
        $self = new self();
        $body = $function instanceof Node\PropertyHook ? $function->body : ($function instanceof Expr\ArrowFunction ? $function->expr : $function->getStmts());
        $self->walk($body);
        foreach ($errors as $error) {
            \array_push($self->dead, ...$self->deadBy($error));
        }

        return $self;
    }

    public function isEmpty(): bool
    {
        return $this->dead === [];
    }

    /**
     * Probes of {@see Instrumenter::sites()} that mark dead code.
     *
     * @param list<array{int, Stmt\If_|null}> $sites
     * @return list<int>
     */
    public function probes(array $sites): array
    {
        $result = [];
        foreach ($sites as $id => [$position, $if]) {
            foreach ($this->dead as $dead) {
                if ($dead->contains($position) || ($if !== null && $dead->missingElseOf === $if)) {
                    $result[] = $id;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Lines that hold nothing but dead code (for line coverage by Xdebug or pcov).
     *
     * @return list<int>
     */
    public function lines(string $code): array
    {
        $lines = [];
        $starts = [0];
        $offset = 0;
        while (($break = \strpos($code, "\n", $offset)) !== false) {
            $starts[] = $offset = $break + 1;
        }

        foreach ($this->dead as $dead) {
            if ($dead->missingElseOf !== null) {
                continue;
            }

            for ($line = $dead->startLine; $line <= $dead->endLine; ++$line) {
                $from = $starts[$line - 1] ?? \strlen($code);
                $to = ($starts[$line] ?? \strlen($code) + 1) - 1;
                $text = \substr($code, $from, $to - $from);
                $live = false;
                foreach (\str_split($text) as $i => $char) {
                    if (!\ctype_space($char) && !$this->isDead($from + $i)) {
                        $live = true;
                        break;
                    }
                }

                $live || \trim($text) === '' or $lines[$line] = true;
            }
        }

        $result = \array_keys($lines);
        \sort($result);

        return $result;
    }

    /**
     * @param Node|array<Node> $nodes
     * @return list<Dead>
     */
    private static function range(Node|array $nodes): array
    {
        $nodes = \is_array($nodes) ? \array_values($nodes) : [$nodes];
        if ($nodes === []) {
            return [];
        }

        $first = $nodes[0];
        $last = $nodes[\count($nodes) - 1];

        return [new Dead($first->getStartFilePos(), $last->getEndFilePos(), $first->getStartLine(), $last->getEndLine())];
    }

    private function isDead(int $position): bool
    {
        foreach ($this->dead as $dead) {
            if ($dead->contains($position)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Error $error
     * @return list<Dead>
     */
    private function deadBy(array $error): array
    {
        [$prefix, $suffix] = \explode('.', $error['identifier'], 2) + [1 => ''];
        $line = $error['line'];

        if ($error['identifier'] === 'deadCode.unreachable') {
            $found = \array_values(\array_filter($this->unreachable, static fn(array $u): bool => $u[0]->getStartLine() === $line));

            return \count($found) === 1 ? $found[0][1] : [];
        }

        if ($prefix === 'nullCoalesce') {
            # "… on left side of ?? always exists and is not nullable": the right side never runs.
            if (!\str_contains($error['message'], 'not nullable')) {
                return [];
            }

            $found = \array_values(\array_filter($this->nodes, static fn(Node $n): bool => $n instanceof Expr\BinaryOp\Coalesce && $n->getStartLine() === $line));

            return \count($found) === 1 && $found[0] instanceof Expr\BinaryOp\Coalesce ? self::range($found[0]->right) : [];
        }

        $verdict = match ($suffix) {
            'alwaysTrue', 'alreadyNarrowedType', 'leftAlwaysTrue' => true,
            'alwaysFalse', 'impossibleType', 'leftAlwaysFalse' => false,
            default => null,
        };
        if ($verdict === null) {
            return [];
        }

        if (isset(self::DECISIONS[$prefix]) && \in_array($suffix, $prefix === 'if' || $prefix === 'elseif' || $prefix === 'while' || $prefix === 'ternary' || $prefix === 'match' ? ['alwaysTrue', 'alwaysFalse'] : ['leftAlwaysTrue', 'leftAlwaysFalse'], true)) {
            $kind = self::DECISIONS[$prefix];
            $found = \array_values(\array_filter($this->decisions, static fn(array $d): bool => $d['kind'] === $kind && $d['node']->getStartLine() === $line));

            return \count($found) === 1 ? $found[0][$verdict ? 'true' : 'false'] : [];
        }

        if (!isset(self::CHECKS[$prefix]) || !\in_array($suffix, ['alwaysTrue', 'alwaysFalse', 'alreadyNarrowedType', 'impossibleType'], true)) {
            return [];
        }

        # The one expression of this kind on the line, and it must be a whole condition.
        $classes = self::CHECKS[$prefix];
        $expressions = \array_values(\array_filter($this->nodes, static fn(Node $n): bool => \in_array($n::class, $classes, true) && $n->getStartLine() === $line));
        if (\count($expressions) !== 1) {
            return [];
        }

        $found = \array_values(\array_filter($this->decisions, static fn(array $d): bool => $d['cond'] === $expressions[0]));

        return \count($found) === 1 ? $found[0][$verdict ? 'true' : 'false'] : [];
    }

    private function walk(mixed $node): void
    {
        if (\is_array($node)) {
            /** @var mixed $child */
            foreach ($node as $child) {
                $this->walk($child);
            }

            $this->statements($node);

            return;
        }

        if (!$node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
            return;
        }

        $this->nodes[] = $node;
        match (true) {
            $node instanceof Stmt\If_ => $this->if($node),
            $node instanceof Stmt\While_ => $this->decide('while', $node, $node->cond, [], self::range($node->stmts)),
            $node instanceof Expr\Ternary => $this->decide('ternary', $node, $node->cond, self::range($node->else), $node->if === null ? [] : self::range($node->if)),
            $node instanceof Expr\BinaryOp\BooleanAnd, $node instanceof Expr\BinaryOp\LogicalAnd => $this->decide('and', $node, $node->left, [], self::range($node->right)),
            $node instanceof Expr\BinaryOp\BooleanOr, $node instanceof Expr\BinaryOp\LogicalOr => $this->decide('or', $node, $node->left, self::range($node->right), []),
            $node instanceof Expr\Match_ => $this->match($node),
            default => null,
        };

        foreach ($node->getSubNodeNames() as $name) {
            $name === 'attrGroups' or $this->walk($node->{$name});
        }
    }

    private function if(Stmt\If_ $node): void
    {
        $rest = [];
        foreach ($node->elseifs as $elseif) {
            \array_push($rest, ...self::range($elseif));
        }

        $node->else === null or \array_push($rest, ...self::range($node->else));
        $missing = $node->else === null ? [new Dead(-1, -1, 0, -1, $node)] : [];
        $this->decide('if', $node, $node->cond, [...$rest, ...$missing], self::range($node->stmts));

        foreach (\array_values($node->elseifs) as $i => $elseif) {
            $later = [];
            foreach (\array_slice(\array_values($node->elseifs), $i + 1) as $next) {
                \array_push($later, ...self::range($next));
            }

            $node->else === null or \array_push($later, ...self::range($node->else));
            $this->decide('elseif', $elseif, $elseif->cond, [...$later, ...$missing], self::range($elseif->stmts));
        }
    }

    private function match(Expr\Match_ $node): void
    {
        # `match (true)`: an arm whose only condition is always false never runs.
        $subjectTrue = $node->cond instanceof Expr\ConstFetch && \strtolower($node->cond->name->toString()) === 'true';
        foreach ($node->arms as $arm) {
            $single = $arm->conds !== null && \count($arm->conds) === 1;
            $this->decide('arm', $arm, $subjectTrue && $single ? $arm->conds[0] : null, [], self::range($arm->body));
        }
    }

    /**
     * @param list<Dead> $true Dead when the condition is always true.
     * @param list<Dead> $false Dead when it is always false.
     */
    private function decide(string $kind, Node $node, ?Expr $cond, array $true, array $false): void
    {
        $this->decisions[] = ['kind' => $kind, 'node' => $node, 'cond' => $cond, 'true' => $true, 'false' => $false];
    }

    /**
     * @param array<mixed> $list
     */
    private function statements(array $list): void
    {
        $stmts = \array_values(\array_filter($list, static fn(mixed $s): bool => $s instanceof Stmt));
        foreach ($stmts as $i => $stmt) {
            $i > 0 and $this->unreachable[] = [$stmt, self::range(\array_slice($stmts, $i))];
        }
    }
}
