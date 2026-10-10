<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Opmin\Module\Config\Schema;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Readability thresholds of a change (brief, «Читаемость»): the gain must be worth the changed
 * lines, and cyclomatic complexity, nesting depth and the forbidden patterns must not grow.
 *
 * Metrics are measured on the unit's own body: nested closures and classes are units of their own.
 *
 * @internal
 */
final readonly class Readability
{
    /** Patterns `readability.forbid_patterns` may name. */
    public const PATTERNS = ['nested_ternary', 'assignment_in_condition', 'goto'];

    public function __construct(
        private Schema\Readability $config,
    ) {}

    /**
     * Cyclomatic complexity: 1 + decision points.
     */
    public static function complexity(Node\FunctionLike $function): int
    {
        $complexity = 1;
        foreach (self::ownNodes($function) as $node) {
            $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_
            || $node instanceof Stmt\Catch_ || $node instanceof Expr\Ternary || $node instanceof Expr\BinaryOp\Coalesce
            || $node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\BooleanOr
            || $node instanceof Expr\BinaryOp\LogicalAnd || $node instanceof Expr\BinaryOp\LogicalOr
            || $node instanceof Expr\BinaryOp\LogicalXor || $node instanceof Expr\AssignOp\Coalesce
            || ($node instanceof Stmt\Case_ && $node->cond !== null)
            || ($node instanceof Node\MatchArm && $node->conds !== null) and ++$complexity;
        }

        return $complexity;
    }

    /**
     * Deepest nesting of control structures.
     */
    public static function nesting(Node\FunctionLike $function): int
    {
        return self::depth((array) $function->getStmts());
    }

    /**
     * Occurrences of each forbidden pattern.
     *
     * @return array<non-empty-string, int>
     */
    public static function patterns(Node\FunctionLike $function): array
    {
        $found = \array_fill_keys(self::PATTERNS, 0);
        foreach (self::ownNodes($function) as $node) {
            if ($node instanceof Stmt\Goto_) {
                ++$found['goto'];
            }

            if ($node instanceof Expr\Ternary) {
                foreach ([$node->cond, $node->if, $node->else] as $part) {
                    $part instanceof Expr\Ternary and ++$found['nested_ternary'];
                }
            }

            $conditions = match (true) {
                $node instanceof Stmt\If_, $node instanceof Stmt\ElseIf_, $node instanceof Stmt\While_,
                $node instanceof Stmt\Do_, $node instanceof Expr\Ternary => [$node->cond],
                $node instanceof Stmt\For_ => $node->cond,
                default => [],
            };
            foreach ($conditions as $condition) {
                $found['assignment_in_condition'] += self::assignments($condition);
            }
        }

        return $found;
    }

    /**
     * Why a change of one unit is not worth it, or null when it is.
     *
     * @param int $gain Opcodes saved (negative: grown).
     * @param int<0, max> $changedLines
     * @param bool $executedGain The rule saves executed opcodes, not static ones: a gain of 0 is enough.
     */
    public function reject(int $gain, int $changedLines, ?Node\FunctionLike $before, ?Node\FunctionLike $after, bool $executedGain): ?string
    {
        $minGain = \max(1, $this->config->minGain);
        if ($executedGain ? $gain < 0 : $gain < $minGain) {
            return match (true) {
                $gain < 0 => 'opcodes grew by ' . -$gain,
                $gain === 0 => 'saves no opcodes',
                default => "saves only {$gain} opcode(s), readability.min_gain is {$minGain}",
            };
        }

        if (!$executedGain && $changedLines > 0 && $gain / $changedLines < $this->config->minGainPerLine) {
            return \sprintf(
                'saves %d opcode(s) for %d changed line(s), less than readability.min_gain_per_line %s per line',
                $gain,
                $changedLines,
                $this->config->minGainPerLine,
            );
        }

        return $before === null || $after === null ? null : $this->compare($before, $after);
    }

    /**
     * Why the shape of a unit got worse, or null.
     */
    public function compare(Node\FunctionLike $before, Node\FunctionLike $after): ?string
    {
        $complexity = self::complexity($after) - self::complexity($before);
        if ($complexity > $this->config->maxCyclomaticIncrease) {
            return "cyclomatic complexity grew by {$complexity} (more branches; readability.max_cyclomatic_increase is {$this->config->maxCyclomaticIncrease})";
        }

        $nesting = self::nesting($after) - self::nesting($before);
        if ($nesting > $this->config->maxNestingIncrease) {
            return "nesting grew by {$nesting} (code more levels deep; readability.max_nesting_increase is {$this->config->maxNestingIncrease})";
        }

        $was = self::patterns($before);
        foreach (self::patterns($after) as $pattern => $count) {
            if (\in_array($pattern, $this->config->forbidPatterns, true) && $count > $was[$pattern]) {
                return "introduces {$pattern} (forbidden by readability.forbid_patterns)";
            }
        }

        return null;
    }

    /**
     * @param array<array-key, Node> $stmts
     */
    private static function depth(array $stmts): int
    {
        $max = 0;
        foreach ($stmts as $stmt) {
            $inner = match (true) {
                $stmt instanceof Stmt\If_ => [$stmt->stmts, ...\array_map(static fn(Stmt\ElseIf_ $e): array => $e->stmts, $stmt->elseifs), $stmt->else?->stmts ?? []],
                $stmt instanceof Stmt\For_, $stmt instanceof Stmt\Foreach_, $stmt instanceof Stmt\While_,
                $stmt instanceof Stmt\Do_ => [$stmt->stmts],
                $stmt instanceof Stmt\Switch_ => \array_map(static fn(Stmt\Case_ $c): array => $c->stmts, $stmt->cases),
                $stmt instanceof Stmt\TryCatch => [$stmt->stmts, ...\array_map(static fn(Stmt\Catch_ $c): array => $c->stmts, $stmt->catches), $stmt->finally?->stmts ?? []],
                default => null,
            };
            if ($inner === null) {
                $stmt instanceof Stmt\Block and $max = \max($max, self::depth($stmt->stmts));
                continue;
            }

            foreach ($inner as $list) {
                $max = \max($max, 1 + self::depth($list));
            }
        }

        return $max;
    }

    private static function assignments(Expr $expr): int
    {
        $count = 0;
        $stack = [$expr];
        while ($stack !== []) {
            $node = \array_pop($stack);
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                continue;
            }

            ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef) and ++$count;
            foreach ($node->getSubNodeNames() as $name) {
                /** @var mixed $child */
                $child = $node->{$name};
                $child instanceof Node and $stack[] = $child;
                if (\is_array($child)) {
                    /** @var mixed $item */
                    foreach ($child as $item) {
                        $item instanceof Node and $stack[] = $item;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @return \Generator<Node>
     */
    private static function ownNodes(Node\FunctionLike $function): \Generator
    {
        $stack = \array_reverse((array) $function->getStmts());
        while ($stack !== []) {
            $node = \array_pop($stack);
            yield $node;
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\Function_
                || $node instanceof Stmt\ClassLike
            ) {
                continue;
            }

            $children = [];
            foreach ($node->getSubNodeNames() as $name) {
                /** @var mixed $child */
                $child = $node->{$name};
                $child instanceof Node and $children[] = $child;
                if (\is_array($child)) {
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
