<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `$a . '/' . $b . '/' . $c` → `"{$a}/{$b}/{$c}"`: a chain of `.` becomes one interpolated string.
 *
 * The compiler builds an interpolated string of three and more parts as a rope (`ROPE_INIT`,
 * `ROPE_ADD`, `ROPE_END`): one allocation of the result instead of one per `.`. It is an opcode more
 * than the chain, but 9–12 % fewer CPU instructions per call on PHP 8.1–8.5 without the JIT and 28–30 %
 * fewer with it (`docs/rule-cost.md`): the pipeline accepts it by cost ({@see self::COST_GAIN}). Without
 * the JIT it costs more on empty operands — `.` returns the other operand without allocating, the rope
 * always allocates — which the strings it rewrites (paths, keys, messages) rarely are.
 *
 * The chain is rewritten from its start up to the first operand `{…}` cannot hold: local variables,
 * property fetches, array elements with a literal or variable key, method calls on them, string and
 * integer literals, interpolated strings. The rest stays concatenated to the result:
 * `$a . '/' . f()` keeps its form, `$a . '/' . $b . f()` becomes `"{$a}/{$b}" . f()`. Less than three
 * parts after merging adjacent literals is left alone: the compiler makes a single `FAST_CONCAT` of it
 * anyway.
 *
 * Each operand is converted to a string at the same moment as before, left to right. One exception:
 * `.` reads a local variable on its left only when the right operand is evaluated, the rope reads it
 * first — so no later operand may write a variable operand (`$i . '-' . $i++`).
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/ConcatToInterpolation')]
final class ConcatToInterpolationRector extends AbstractRector
{
    /** Cheaper by execution cost, not by the static count: accepted even when the count grows. */
    public const bool COST_GAIN = true;

    /** Fewer parts are compiled to one `FAST_CONCAT`, not a rope. */
    private const int MIN_PARTS = 3;

    /** Attribute of a `.` of a chain already looked at from its top. */
    private const string SEEN = 'opmin_concat_seen';

    public static function alias(): string
    {
        return 'interpolate';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Turn a chain of concatenations into one interpolated string', [
            new ConfiguredCodeSample(
                "return \$dir . '/' . \$name . '.' . \$this->extension;",
                'return "{$dir}/{$name}.{$this->extension}";',
                [],
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Stmt\ClassMethod::class, Stmt\Function_::class, Expr\Closure::class];
    }

    /**
     * @param Stmt\ClassMethod|Stmt\Function_|Expr\Closure $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->stmts === null || FunctionBody::ignored($node, self::alias())) {
            return null;
        }

        $referenced = FunctionBody::referencedVariables($node);
        $changed = false;
        $this->traverseNodesWithCallable($node->stmts, function (Node $n) use ($referenced, &$changed): int|Node|null {
            # Nested functions are refactored on their own, with their own ignore marks.
            if ($n instanceof Expr\Closure || $n instanceof Stmt\Function_ || $n instanceof Stmt\ClassLike) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            if (!$n instanceof Expr\BinaryOp\Concat || $n->getAttribute(self::SEEN) === true) {
                return null;
            }

            $result = $this->interpolate($n, $referenced);
            $result === null or $changed = true;

            return $result;
        });

        return $changed ? $node : null;
    }

    /**
     * Whether `{…}` holds the operand as it is.
     */
    private static function interpolable(Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Expr\Variable => \is_string($expr->name),
            $expr instanceof Expr\PropertyFetch, $expr instanceof Expr\NullsafePropertyFetch,
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $expr->name instanceof Node\Identifier && self::interpolable($expr->var),
            $expr instanceof Expr\ArrayDimFetch => ($expr->dim instanceof Scalar\String_ || $expr->dim instanceof Scalar\Int_
                || ($expr->dim instanceof Expr\Variable && \is_string($expr->dim->name))) && self::interpolable($expr->var),
            default => false,
        };
    }

    /**
     * The text of a literal operand, null for any other.
     */
    private static function literal(Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\Int_ => (string) $expr->value,
            default => null,
        };
    }

    /**
     * Whether no operand writes a variable operand left of it: `.` reads a variable when the operand
     * right of it is evaluated, the rope — before.
     *
     * @param list<Expr> $operands
     * @param array<string, true> $referenced
     */
    private static function keepsOrder(array $operands, array $referenced): bool
    {
        foreach ($operands as $i => $operand) {
            if (!$operand instanceof Expr\Variable || !\is_string($operand->name)) {
                continue;
            }

            foreach (\array_slice($operands, $i + 1) as $later) {
                if (FunctionBody::mayWrite($later, $operand->name, $referenced)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The chain from its top `.` rewritten up to the first operand `{…}` cannot hold, or null.
     *
     * @param array<string, true> $referenced
     */
    private function interpolate(Expr\BinaryOp\Concat $top, array $referenced): ?Expr
    {
        # `((o1 . o2) . o3) . o4`: the left spine bottom-up, so that $spine[$i]->right is the operand $i + 1.
        /** @var list<Expr\BinaryOp\Concat> $spine */
        $spine = [];
        $bottom = $top;
        while ($bottom instanceof Expr\BinaryOp\Concat) {
            $bottom->setAttribute(self::SEEN, true);
            \array_unshift($spine, $bottom);
            $bottom = $bottom->left;
        }

        $operands = [$bottom, ...\array_map(static fn(Expr\BinaryOp\Concat $c): Expr => $c->right, $spine)];
        $parts = [];
        $text = null;
        $variables = 0;
        $taken = 0;
        foreach ($operands as $operand) {
            $literal = self::literal($operand);
            if ($literal !== null) {
                $text = ($text ?? '') . $literal;
            } elseif ($operand instanceof Scalar\InterpolatedString) {
                foreach ($operand->parts as $part) {
                    if ($part instanceof InterpolatedStringPart) {
                        $text = ($text ?? '') . $part->value;
                        continue;
                    }

                    $text === null || $text === '' or $parts[] = $text;
                    $text = null;
                    $parts[] = $part;
                    ++$variables;
                }
            } elseif (self::interpolable($operand)) {
                $text === null || $text === '' or $parts[] = $text;
                $text = null;
                $parts[] = $operand;
                ++$variables;
            } else {
                break;
            }

            ++$taken;
        }

        $text === null || $text === '' or $parts[] = $text;
        if ($taken < 2 || \count($parts) < self::MIN_PARTS || $variables === 0 || !self::keepsOrder(\array_slice($operands, 0, $taken), $referenced)) {
            return null;
        }

        $string = new Scalar\InterpolatedString(\array_map(
            static fn(string|Expr $part): Node => \is_string($part) ? new InterpolatedStringPart($part) : $part,
            $parts,
        ), ['kind' => Scalar\String_::KIND_DOUBLE_QUOTED]);
        if ($taken === \count($operands)) {
            return $string;
        }

        # The rest stays concatenated: the `.` that took the last operand rewritten gets the string.
        $spine[$taken - 1]->left = $string;

        return $top;
    }
}
