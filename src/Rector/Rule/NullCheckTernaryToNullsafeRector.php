<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor;
use PHPStan\Analyser\Scope;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\ValueObject\PhpVersionFeature;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `$x !== null ? $x->a->b() : null` → `$x?->a->b()` (and `$x === null ? null : …`, `null !== $x`,
 * `isset($x)`): one `JMP_NULL` instead of a comparison, a jump and a second read of `$x`
 * (catalog B8; measured −2 on PHP 8.1 and 8.5, −3 when assigned).
 *
 * Fires only when:
 * - `$x` is a local variable (a call or a property would be evaluated twice in the original); for
 *   `isset($x)` it must be surely defined — `isset()` of an undefined variable is silent, `?->`
 *   warns like `$x !== null` does;
 * - the other branch is the literal `null`, the check is strict (`==` is not, `is_null()` may be
 *   shadowed in a namespace);
 * - the non-null branch is a chain of property reads, method calls and array reads that starts
 *   with `$x->` (or `$x?->`): `?->` short-circuits the whole chain, as the ternary did;
 * - the function does not return by reference: `return`/`yield` of a nullsafe chain there is a
 *   compile error ("Cannot take reference of a nullsafe chain"). A by-reference argument or
 *   `foreach (… as &$v)` of a ternary and of a nullsafe chain behave the same.
 *
 * Needs PHP 8.0 (`php.target`).
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/NullCheckTernaryToNullsafe')]
final class NullCheckTernaryToNullsafeRector extends AbstractRector implements MinPhpVersionInterface
{
    public static function alias(): string
    {
        return 'nullsafe';
    }

    public function provideMinPhpVersion(): int
    {
        return PhpVersionFeature::NULLSAFE_OPERATOR;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace a null-check ternary with the nullsafe operator', [
            new ConfiguredCodeSample(
                'return $user !== null ? $user->getName() : null;',
                'return $user?->getName();',
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
        if ($node->stmts === null || $node->byRef || FunctionBody::ignored($node, self::alias())) {
            return null;
        }

        $changed = false;
        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$changed): int|Node|null {
            # Nested functions and classes are refactored as nodes of their own; an arrow function
            # returning by reference cannot return a nullsafe chain.
            if ($inner instanceof Expr\Closure || $inner instanceof Stmt\Function_ || $inner instanceof Stmt\ClassLike
                || ($inner instanceof Expr\ArrowFunction && $inner->byRef)
            ) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            $new = $inner instanceof Expr\Ternary ? $this->nullsafe($inner) : null;
            $new === null or $changed = true;

            return $new;
        });

        return $changed ? $node : null;
    }

    /**
     * `$x` of `$x !== null` / `null !== $x` (or `===`).
     */
    private static function nullChecked(BinaryOp\Identical|BinaryOp\NotIdentical $cond): ?Expr
    {
        return match (true) {
            self::isNull($cond->right) && !self::isNull($cond->left) => $cond->left,
            self::isNull($cond->left) && !self::isNull($cond->right) => $cond->right,
            default => null,
        };
    }

    private static function isNull(Expr $expr): bool
    {
        return $expr instanceof Expr\ConstFetch && $expr->name->toLowerString() === 'null';
    }

    private static function defined(Expr $expr): bool
    {
        /** @var mixed $scope */
        $scope = $expr->getAttribute(AttributeKey::SCOPE);

        return $expr instanceof Expr\Variable && \is_string($expr->name) && $scope instanceof Scope
            && $scope->hasVariableType($expr->name)->yes();
    }

    private function nullsafe(Expr\Ternary $ternary): ?Expr
    {
        if ($ternary->if === null) {
            return null;
        }

        $cond = $ternary->cond;
        [$subject, $chain, $other] = match (true) {
            $cond instanceof BinaryOp\NotIdentical => [self::nullChecked($cond), $ternary->if, $ternary->else],
            $cond instanceof BinaryOp\Identical => [self::nullChecked($cond), $ternary->else, $ternary->if],
            $cond instanceof Expr\Isset_ && \count($cond->vars) === 1 && self::defined($cond->vars[0]) => [$cond->vars[0], $ternary->if, $ternary->else],
            default => [null, null, null],
        };
        if (!$subject instanceof Expr\Variable || !\is_string($subject->name) || $subject->name === 'this'
            || !$chain instanceof Expr || !$other instanceof Expr || !self::isNull($other)
        ) {
            return null;
        }

        return $this->rewrite($chain, $subject->name);
    }

    /**
     * The chain with its first link on `$name` made nullsafe, or null when it is not such a chain.
     */
    private function rewrite(Expr $chain, string $name): ?Expr
    {
        $link = $chain;
        $outer = null;
        while (true) {
            $var = match (true) {
                $link instanceof Expr\PropertyFetch, $link instanceof Expr\NullsafePropertyFetch,
                $link instanceof Expr\MethodCall, $link instanceof Expr\NullsafeMethodCall => $link->var,
                $link instanceof Expr\ArrayDimFetch && $link->dim !== null => $link->var,
                default => null,
            };
            if ($var === null) {
                return null;
            }

            if ($var instanceof Expr\Variable) {
                break;
            }

            $outer = $link;
            $link = $var;
        }

        if (!$var instanceof Expr\Variable || $var->name !== $name || $link instanceof Expr\ArrayDimFetch) {
            return null;
        }

        $first = match (true) {
            $link instanceof Expr\PropertyFetch => new Expr\NullsafePropertyFetch($var, $link->name),
            $link instanceof Expr\MethodCall => new Expr\NullsafeMethodCall($var, $link->name, $link->args),
            default => $link,
        };
        if ($outer === null) {
            return $first;
        }

        /** @var Expr\PropertyFetch|Expr\NullsafePropertyFetch|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\ArrayDimFetch $outer */
        $outer->var = $first;

        return $chain;
    }
}
