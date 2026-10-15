<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `if ($s === 'a') { return 1; } if ($s === 'b') { return 2; } return 0;` →
 * `return match ($s) { 'a' => 1, 'b' => 2, default => 0 };` (catalog B1).
 *
 * String labels only. Measured by execution cost (callgrind, PHP 8.1–8.5, with and without the
 * tracing JIT): the jump table of `match` makes a chain of string comparisons 15–76 % cheaper for
 * every value but the first label (+16…+26 % there); for int labels `match` costs more than the chain
 * up to 5 arms without JIT and at almost any size with JIT, so int chains are left alone. Opcodes:
 * 2 literals −3, 3 literals −5, 5 literals −9 without a return type; with one and literal results:
 * 2 literals 0, 3 literals −1, 5 literals −3 (`min_arms`, {@see self::DEFAULT_MIN_ARMS_TYPED}).
 * Literals of mixed types or constants save nothing (no jump table), so they are left alone.
 *
 * Fires only when:
 * - every condition is `$v === <literal>` (or `<literal> === $v`, or several of them joined by `||`)
 *   of one local variable, all literals strings: `match` compares by `===` and takes the first
 *   matching arm, as the chain does, duplicates included;
 * - the variable is provably defined (PHPStan): an undefined one warns once per comparison in the
 *   chain and once in `match`; nothing else runs between the comparisons, so it cannot change;
 * - every branch is exactly one `return <expr>;` and the chain ends with `return <expr>;` (or an
 *   `else` with one), which becomes `default`: without it `match` would throw `UnhandledMatchError`
 *   where the chain falls through;
 * - the function does not return by reference.
 *
 * Not final: `tests/Rector/IfChainToMatchMinArmsRector.php` runs the fixtures with another
 * `min_arms`, which the Rector bridge of Testo cannot pass.
 *
 * @internal
 * @psalm-suppress ClassMustBeFinal
 */
#[TestRectorFixtures('Fixture/IfChainToMatch')]
class IfChainToMatchRector extends AbstractReturnMatchRector
{
    public static function alias(): string
    {
        return 'if_to_match';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace a chain of `if ($v === \'literal\') return` by `return match`', [
            new ConfiguredCodeSample(
                <<<'PHP'
                    if ($unit === 'kb') {
                        return 1024;
                    }
                    if ($unit === 'mb') {
                        return 1048576;
                    }
                    return 1;
                    PHP,
                <<<'PHP'
                    return match ($unit) {
                        'kb' => 1024,
                        'mb' => 1048576,
                        default => 1,
                    };
                    PHP,
                [self::MIN_ARMS => 'auto'],
            ),
        ]);
    }

    protected function chainAt(array $stmts, int $i): ?array
    {
        $subject = null;
        $kind = null;
        $arms = [];
        $literals = 0;
        for ($j = $i, $n = \count($stmts); $j < $n; ++$j) {
            $stmt = $stmts[$j];
            if ($stmt instanceof Stmt\Return_ && $j > $i) {
                return $stmt->expr === null ? null : $this->chain($j - $i + 1, $subject, [...$arms, new Node\MatchArm(null, $stmt->expr)], $literals);
            }

            if (!$stmt instanceof Stmt\If_) {
                return null;
            }

            foreach ([[$stmt->cond, $stmt->stmts], ...\array_map(static fn(Stmt\ElseIf_ $e): array => [$e->cond, $e->stmts], $stmt->elseifs)] as [$cond, $body]) {
                $values = $this->literals($cond, $subject, $kind);
                $result = self::singleReturn($body);
                if ($values === null || $result === null) {
                    return null;
                }

                $arms[] = new Node\MatchArm($values, $result);
                $literals += \count($values);
            }

            if ($stmt->else !== null) {
                $result = self::singleReturn($stmt->else->stmts);

                return $result === null ? null : $this->chain($j - $i + 1, $subject, [...$arms, new Node\MatchArm(null, $result)], $literals);
            }
        }

        return null;
    }

    /**
     * @param list<Node\MatchArm> $arms
     * @return array{positive-int, Expr, list<Node\MatchArm>, int}|null
     */
    private function chain(int $length, ?Expr\Variable $subject, array $arms, int $literals): ?array
    {
        if ($subject === null || $length < 1 || !\is_string($subject->name)) {
            return null;
        }

        $scope = self::scope($subject);
        if ($scope === null || !$scope->hasVariableType($subject->name)->yes()) {
            return null;
        }

        return [$length, new Expr\Variable($subject->name), $arms, $literals];
    }

    /**
     * The literals `$cond` compares the subject with: `$v === 1`, `1 === $v`, `$v === 1 || $v === 2`.
     * The first comparison fixes the subject and the kind of the literals, the next ones must match.
     *
     * @param-out Expr\Variable|null $subject
     * @return non-empty-list<Expr>|null
     */
    private function literals(Expr $cond, ?Expr\Variable &$subject, ?string &$kind): ?array
    {
        if ($cond instanceof Expr\BinaryOp\BooleanOr) {
            $left = $this->literals($cond->left, $subject, $kind);
            $right = $left === null ? null : $this->literals($cond->right, $subject, $kind);

            return $left === null || $right === null ? null : [...$left, ...$right];
        }

        if (!$cond instanceof Expr\BinaryOp\Identical) {
            return null;
        }

        [$var, $literal] = $cond->left instanceof Expr\Variable ? [$cond->left, $cond->right] : [$cond->right, $cond->left];
        $literalKind = self::literalKind($literal);
        if (!$var instanceof Expr\Variable || !\is_string($var->name) || \in_array($var->name, ['this', 'GLOBALS'], true)
            || $literalKind !== self::STRING || ($kind !== null && $kind !== $literalKind)
            || ($subject !== null && $subject->name !== $var->name)
        ) {
            return null;
        }

        $subject ??= $var;
        $kind = $literalKind;

        return [$literal];
    }
}
