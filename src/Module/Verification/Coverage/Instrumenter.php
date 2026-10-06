<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Coverage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Token;

/**
 * Branch probes for the coverage of the original version (brief 2.4) without Xdebug or pcov:
 * `\Opmin\Harness\Probe::hit(N)` at the entry of the function and at the start of every branch —
 * `if`/`elseif`/`else` (an `else` is added where there is none), loop bodies, `case`, `catch`,
 * `finally`, both arms of `?:`, the right side of `??`, `&&`, `||`, `match` arms.
 *
 * Text is only inserted, never moved, and never contains a line break: the code keeps its lines
 * (`__LINE__`, warnings, traces) and its behavior. A body without braces gets them. Nested
 * functions, closures and classes are units of their own and are not instrumented; nor are
 * `static` initializers and interpolated strings.
 *
 * @internal
 */
final class Instrumenter
{
    private const PROBE = '\Opmin\Harness\Probe::hit(%d)';

    private readonly Parser $parser;

    /** @var list<Token> */
    private array $tokens = [];

    /** @var array<int, list<array{array{int, int}, string}>> Position => [order, text]. */
    private array $edits = [];

    private int $next = 0;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param Node\FunctionLike $function Node of the function in `$code` (positions must match).
     * @return array{string, non-negative-int} The instrumented code and the number of probes.
     */
    public function instrument(string $code, Node\FunctionLike $function): array
    {
        $this->parser->parse($code);
        $this->tokens = \array_values($this->parser->getTokens());
        $this->edits = [];
        $this->next = 0;

        if ($function instanceof Expr\ArrowFunction) {
            $this->wrap($function->expr, 0);
            $this->walk($function->expr, 1);
        } else {
            $body = $function instanceof Node\PropertyHook ? $function->body : $function->getStmts();
            if ($body instanceof Expr) {
                $this->wrap($body, 0);
                $this->walk($body, 1);
            } elseif (\is_array($body) && $body !== []) {
                $this->block($body, 0);
                $this->walk($body, 1);
            }
        }

        return [$this->apply($code), $this->next];
    }

    private function walk(mixed $node, int $depth): void
    {
        if (\is_array($node)) {
            /** @var mixed $child */
            foreach ($node as $child) {
                $this->walk($child, $depth);
            }

            return;
        }

        if (!$node instanceof Node
            || $node instanceof Node\FunctionLike
            || $node instanceof Stmt\ClassLike
            || $node instanceof Stmt\Static_
            || $node instanceof Node\Scalar\InterpolatedString
            || $node instanceof Node\AttributeGroup
        ) {
            # Arguments of `new class(...)` still belong to this function.
            $node instanceof Expr\New_ && $node->class instanceof Stmt\Class_ and $this->walk($node->args, $depth + 1);
            return;
        }

        match (true) {
            $node instanceof Stmt\If_ => $this->if($node, $depth),
            $node instanceof Stmt\For_, $node instanceof Stmt\Foreach_, $node instanceof Stmt\While_, $node instanceof Stmt\Do_ => $this->block($node->stmts, $depth + 1),
            $node instanceof Stmt\Case_ => $this->block($node->stmts, $depth + 1, inline: true),
            $node instanceof Stmt\Catch_, $node instanceof Stmt\Finally_ => $this->block($node->stmts, $depth + 1),
            $node instanceof Expr\Ternary => $this->ternary($node, $depth),
            $node instanceof Expr\BinaryOp\Coalesce,
            $node instanceof Expr\BinaryOp\BooleanAnd,
            $node instanceof Expr\BinaryOp\BooleanOr,
            $node instanceof Expr\BinaryOp\LogicalAnd,
            $node instanceof Expr\BinaryOp\LogicalOr => $this->wrap($node->right, $depth + 1),
            $node instanceof Expr\Match_ => \array_map(fn(Node\MatchArm $arm) => $this->wrap($arm->body, $depth + 1), $node->arms),
            default => null,
        };

        foreach ($node->getSubNodeNames() as $name) {
            $name === 'attrGroups' or $this->walk($node->{$name}, $depth + 1);
        }
    }

    private function if(Stmt\If_ $node, int $depth): void
    {
        $braced = $this->block($node->stmts, $depth + 1);
        foreach ($node->elseifs as $elseif) {
            $braced = $this->block($elseif->stmts, $depth + 1) && $braced;
        }

        if ($node->else !== null) {
            $this->block($node->else->stmts, $depth + 1);
            return;
        }

        # The path where no branch runs: an `else` with a probe. Not for the `if: … endif;` syntax.
        if ($braced && !$this->alternative($node)) {
            $this->closer($node->getEndFilePos() + 1, $depth, ' else { ' . $this->probe() . '; }');
        }
    }

    private function ternary(Expr\Ternary $node, int $depth): void
    {
        $node->if === null or $this->wrap($node->if, $depth + 1);
        $this->wrap($node->else, $depth + 1);
    }

    /**
     * A probe at the start of a statement list. A single statement without braces gets them.
     *
     * @param array<Node\Stmt> $stmts
     * @param bool $inline The list never has braces of its own (`case`).
     * @return bool False when the list is empty (nothing to probe).
     */
    private function block(array $stmts, int $depth, bool $inline = false): bool
    {
        if ($stmts === []) {
            return true;
        }

        $first = $stmts[\array_key_first($stmts)];
        $start = $first->getStartFilePos();
        $before = $this->significantBefore($start);
        if ($inline || $before === null || \in_array($before->text, ['{', ':', ';'], true)) {
            $this->opener($start, $depth, $this->probe() . '; ');
            return true;
        }

        # `if ($x) return 1;` — wrap the one statement.
        $last = $stmts[\array_key_last($stmts)];
        $this->opener($start, $depth, '{ ' . $this->probe() . '; ');
        $this->closer($last->getEndFilePos() + 1, $depth, ' }');

        return true;
    }

    /**
     * `(\Opmin\Harness\Probe::hit(N) ?? (expr))`: the probe returns null, so the value is the expression's.
     */
    private function wrap(Expr $expr, int $depth): void
    {
        $this->opener($expr->getStartFilePos(), $depth, '(' . $this->probe() . ' ?? (');
        $this->closer($expr->getEndFilePos() + 1, $depth, '))');
    }

    /**
     * `if (...): … endif;` — the token after the condition's `)` is `:`.
     */
    private function alternative(Stmt\If_ $node): bool
    {
        $paren = $this->significantAfter($node->cond->getEndFilePos() + 1);
        $next = $paren === null ? null : $this->significantAfter($paren->pos + 1);

        return $next !== null && $next->text === ':';
    }

    private function significantAfter(int $pos): ?Token
    {
        foreach ($this->tokens as $token) {
            if ($token->pos >= $pos && !$token->isIgnorable()) {
                return $token;
            }
        }

        return null;
    }

    private function significantBefore(int $pos): ?Token
    {
        $low = 0;
        $high = \count($this->tokens) - 1;
        # The last token that starts before the position.
        while ($low < $high) {
            $mid = \intdiv($low + $high + 1, 2);
            $this->tokens[$mid]->pos < $pos ? $low = $mid : $high = $mid - 1;
        }

        for ($i = $low; $i >= 0; --$i) {
            $token = $this->tokens[$i];
            if ($token->pos < $pos && !$token->isIgnorable()) {
                return $token;
            }
        }

        return null;
    }

    private function probe(): string
    {
        return \sprintf(self::PROBE, $this->next++);
    }

    /**
     * Text that opens something at a position: outer ones first.
     */
    private function opener(int $pos, int $depth, string $text): void
    {
        $this->edits[$pos][] = [[1, $depth], $text];
    }

    /**
     * Text that closes something at a position: inner ones first, before any opener.
     */
    private function closer(int $pos, int $depth, string $text): void
    {
        $this->edits[$pos][] = [[0, -$depth], $text];
    }

    private function apply(string $code): string
    {
        \krsort($this->edits);
        foreach ($this->edits as $pos => $edits) {
            \usort($edits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $code = \substr($code, 0, $pos) . \implode('', \array_column($edits, 1)) . \substr($code, $pos);
        }

        return $code;
    }
}
