<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Module\Analysis\IgnoreMarks;
use Opmin\Rector\Support\FunctionBody;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `function () use ($s, $i) { return $i; }` → `function () use ($i) { … }`: every captured variable
 * costs a `BIND_LEXICAL` where the closure is created and a `BIND_STATIC` in the closure (measured
 * by `bin/bench`: −2 per removed variable on PHP 8.1–8.5).
 *
 * Fires only for a by-value `use` (a by-reference one creates the variable in the parent) of a
 * variable that:
 * - the closure's body never names: nested closures, their `use` lists and arrow functions (they
 *   capture by name automatically) included;
 * - cannot be reached by name either: no `compact()`, `extract()`, `get_defined_vars()`,
 *   `parse_str()`, `$$name`, `eval`, `include` in the body ({@see FunctionBody::allowsNewVariables()});
 * - is defined where the closure is created (capturing an undefined one warns) and is not bound by
 *   reference in the parent (the type PHPStan sees may be changed through the reference);
 * - has a native type of scalars, `null` and arrays of them. The closure keeps its captured values
 *   alive: an object (or an array that may hold one, a resource) captured only by the closure would
 *   be released earlier without the `use` — `__destruct()` of it or of anything it holds would run at
 *   another moment, a `WeakReference` would see it gone, a file handle would close. A final class
 *   without a destructor is not enough: its properties may hold anything.
 *
 * A closure, function, method or class marked with `#[\Opmin\Ignore]` (for this rule) keeps the
 * closures inside it as they are; a closure outside any function or method is not touched.
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/RemoveUnusedClosureUse')]
final class RemoveUnusedClosureUseRector extends AbstractRector
{
    /** Set on the function-likes inside an ignored declaration. */
    private const string IGNORED = 'opmin_closure_use_ignored';

    /** Set on a closure by the function it is created in: the variables of that function bound by reference. */
    private const string PARENT_REFERENCED = 'opmin_closure_use_parent_referenced';

    public static function alias(): string
    {
        return 'closure_use';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Remove variables a closure captures but never uses', [
            new CodeSample(
                '$f = static function () use ($prefix, $limit) { return $limit * 2; };',
                '$f = static function () use ($limit) { return $limit * 2; };',
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Stmt\ClassLike::class, Stmt\ClassMethod::class, Stmt\Function_::class, Expr\Closure::class];
    }

    /**
     * @param Stmt\ClassLike|Stmt\ClassMethod|Stmt\Function_|Expr\Closure $node
     */
    public function refactor(Node $node): ?Node
    {
        # Declarations are visited outside in: an ignored one marks everything inside it first.
        if ($node->getAttribute(self::IGNORED) === true || IgnoreMarks::ignored($node, [self::alias(), 'removeunusedclosureuserector'])) {
            foreach ((new NodeFinder())->findInstanceOf($node instanceof Stmt\ClassLike ? $node->stmts : (array) $node->getStmts(), Node\FunctionLike::class) as $inner) {
                $inner->setAttribute(self::IGNORED, true);
            }

            return null;
        }

        if ($node instanceof Stmt\ClassLike || $node->getStmts() === null) {
            return null;
        }

        $referenced = FunctionBody::referencedVariables($node);
        foreach (FunctionBody::ownNodes($node) as $inner) {
            $inner instanceof Expr\Closure and $inner->setAttribute(self::PARENT_REFERENCED, $referenced);
        }

        return $node instanceof Expr\Closure ? $this->removeUnused($node) : null;
    }

    /**
     * The variable never holds an object or a resource, whatever is passed: scalars, `null` and
     * arrays of them.
     */
    private static function plain(Type $type, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }

        $scalar = new UnionType([new IntegerType(), new FloatType(), new StringType(), new BooleanType(), new NullType()]);
        if ($scalar->isSuperTypeOf($type)->yes()) {
            return true;
        }

        if ($type instanceof UnionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::plain($member, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $type->isArray()->yes() && self::plain($type->getIterableValueType(), $depth + 1);
    }

    private function removeUnused(Expr\Closure $closure): ?Expr\Closure
    {
        /** @var mixed $referenced */
        $referenced = $closure->getAttribute(self::PARENT_REFERENCED);
        /** @var mixed $scope */
        $scope = $closure->getAttribute(AttributeKey::SCOPE);
        if ($closure->uses === [] || !\is_array($referenced) || !$scope instanceof Scope || !FunctionBody::allowsNewVariables($closure)) {
            return null;
        }

        $named = [];
        foreach ((new NodeFinder())->findInstanceOf($closure->stmts, Expr\Variable::class) as $variable) {
            \is_string($variable->name) and $named[$variable->name] = true;
        }

        $uses = [];
        foreach ($closure->uses as $use) {
            $name = $use->var->name;
            $unused = !$use->byRef && \is_string($name) && !isset($named[$name]) && !isset($referenced[$name])
                && $scope->hasVariableType($name)->yes() && self::plain($scope->getNativeType($use->var));
            $unused or $uses[] = $use;
        }

        if (\count($uses) === \count($closure->uses)) {
            return null;
        }

        $closure->uses = $uses;

        return $closure;
    }
}
