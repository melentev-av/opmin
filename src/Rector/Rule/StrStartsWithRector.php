<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use Opmin\Rector\Support\GlobalFunctionCall;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor;
use PHPStan\Analyser\Scope;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `\substr($s, 0, 3) === 'abc'` and `\strpos($s, 'abc') === 0` → `\str_starts_with($s, 'abc')`;
 * `!==` → `!\str_starts_with(...)`, either side of the comparison.
 *
 * Measured on PHP 8.1–8.5: `substr` −2 opcodes (−1 with `!==` as a value, −2 in a condition),
 * `strpos` −1 (0 with `!==` as a value, −1 in a condition). `\substr($s, -3) === 'abc'` →
 * `\str_ends_with()` is not done: +2 on PHP 8.4+.
 *
 * Fires only when the result is provably the same:
 * - a strict comparison (`==` compares numeric strings as numbers, `false == 0`);
 * - `substr()` with the literal offset `0` and a literal length equal to the length of the literal
 *   string it is compared with;
 * - `strpos()` with two arguments compared with the literal `0` (for an empty needle both are
 *   true since PHP 8.0);
 * - the haystack, and the needle of `strpos()`, are strings by native type (no coercion, no
 *   `__toString()`, no `TypeError` in one form only).
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/StrStartsWith')]
final class StrStartsWithRector extends AbstractRector
{
    public static function alias(): string
    {
        return 'str_starts_with';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Turn a prefix check with substr() or strpos() into str_starts_with()', [
            new ConfiguredCodeSample(
                "return \\substr(\$path, 0, 1) === '/';",
                "return \\str_starts_with(\$path, '/');",
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

        $changed = false;
        $this->traverseNodesWithCallable($node->stmts, function (Node $n) use (&$changed): int|Node|null {
            # Nested functions are refactored on their own, with their own ignore marks.
            if ($n instanceof Expr\Closure || $n instanceof Stmt\Function_ || $n instanceof Stmt\ClassLike) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            if (!$n instanceof Expr\BinaryOp\Identical && !$n instanceof Expr\BinaryOp\NotIdentical) {
                return null;
            }

            $call = $this->startsWith($n->left, $n->right) ?? $this->startsWith($n->right, $n->left);
            if ($call === null) {
                return null;
            }

            $changed = true;

            return $n instanceof Expr\BinaryOp\Identical ? $call : new Expr\BooleanNot($call);
        });

        return $changed ? $node : null;
    }

    private static function string(Expr $expr): bool
    {
        /** @var mixed $scope */
        $scope = $expr->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope && $scope->getNativeType($expr)->isString()->yes();
    }

    private static function intLiteral(Expr $expr, int $value): bool
    {
        return $expr instanceof Scalar\Int_ && $expr->value === $value;
    }

    private static function call(Expr $haystack, Expr $prefix): Expr\FuncCall
    {
        return new Expr\FuncCall(new Name\FullyQualified('str_starts_with'), [new Node\Arg($haystack), new Node\Arg($prefix)]);
    }

    /**
     * `\str_starts_with($s, $prefix)` for `$call === $other`, or null.
     */
    private function startsWith(Expr $call, Expr $other): ?Expr\FuncCall
    {
        if (!$call instanceof Expr\FuncCall) {
            return null;
        }

        $args = \array_map(static fn(Node\Arg $a): Expr => $a->value, $call->getArgs());
        if (GlobalFunctionCall::plain($call, 'substr')) {
            return \count($args) === 3 && self::intLiteral($args[1], 0) && $other instanceof Scalar\String_
                && self::intLiteral($args[2], \strlen($other->value)) && self::string($args[0])
                ? self::call($args[0], $other)
                : null;
        }

        if (GlobalFunctionCall::plain($call, 'strpos')) {
            return \count($args) === 2 && self::intLiteral($other, 0) && self::string($args[0]) && self::string($args[1])
                ? self::call($args[0], $args[1])
                : null;
        }

        return null;
    }
}
