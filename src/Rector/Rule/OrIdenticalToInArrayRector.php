<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\Reflection\ReflectionResolver;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `$s === 'a' || $s === 'b' || $s === 'c'` → `\in_array($s, ['a', 'b', 'c'], true)`, and
 * `$s !== 'a' && $s !== 'b'` → `!\in_array($s, ['a', 'b'], true)`: the compiler turns `\in_array()`
 * of a constant array of strings and integers into one `IN_ARRAY` opcode with a hash lookup instead
 * of a comparison and a jump per item (catalog B4).
 *
 * Measured on PHP 8.1 and 8.5 (`||` / `&&`): 2 items −2 / −1, 3 items −4 / −3, 5 items −8. Floats,
 * `null` and booleans are left alone: `IN_ARRAY` takes only strings and integers, a regular call of
 * `in_array()` costs +2 on PHP 8.1. Constants of other classes, inherited ones and `static::` are not
 * substituted at compile time and cost +3…+4.
 *
 * Fires on a run of adjacent operands of one `||` (`&&`) chain — other operands keep their place and
 * order — each a strict `===` (`!==`) of the same subject and an item:
 * - an item is an integer or string literal (`-1` too) or a constant of the class itself
 *   (`self::A`, `Foo::A` inside `Foo`, not in a trait) whose value is such a literal: evaluating
 *   it runs no code, as when the chain stopped before it;
 * - the subject is read once instead of up to N times, so the reads must not differ: a local
 *   variable that is surely defined (an undefined one warns on every read), a property or an
 *   array element as safe to read repeatedly as for {@see ExtractRepeatedPropertyFetchRector} and
 *   {@see ExtractRepeatedArrayDimFetchRector} (no `__get`, hooks, `offsetGet()`, missing key). A
 *   call is never a subject. Nothing between the comparisons can change it: an item runs no code.
 * - `\in_array()` is written fully qualified: it cannot be shadowed by a namespaced function or a
 *   test mock, and only the qualified name compiles to `IN_ARRAY`.
 *
 * Loose `==` is not touched. `min_items` (default 2) is the shortest run worth it.
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/OrIdenticalToInArray')]
final class OrIdenticalToInArrayRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string MIN_ITEMS = 'min_items';

    /** Measured: `in_array()` of 2 items saves 2 opcodes with `||`, 1 with `&&`. */
    public const int DEFAULT_MIN_ITEMS = 2;

    /** @var int<2, max> */
    private int $minItems = self::DEFAULT_MIN_ITEMS;

    private ?ExtractRepeatedPropertyFetchRector $properties = null;
    private ?ExtractRepeatedArrayDimFetchRector $dims = null;

    public function __construct(
        private readonly ReflectionResolver $reflectionResolver,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public static function alias(): string
    {
        return 'in_array';
    }

    /**
     * @param array<array-key, mixed> $configuration `min_items`: an integer ≥ 2.
     */
    public function configure(array $configuration): void
    {
        /** @var mixed $minItems */
        $minItems = $configuration[self::MIN_ITEMS] ?? self::DEFAULT_MIN_ITEMS;
        \is_int($minItems) && $minItems >= 2 or throw new \InvalidArgumentException(
            self::class . ': `min_items` must be an integer of at least 2, got ' . \get_debug_type($minItems) . '.',
        );
        $this->minItems = $minItems;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace strict comparisons of one value with several literals by in_array()', [
            new ConfiguredCodeSample(
                "return \$s === 'a' || \$s === 'b' || \$s === 'c';",
                "return \\in_array(\$s, ['a', 'b', 'c'], true);",
                [self::MIN_ITEMS => 2],
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
        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$changed): int|Node|null {
            # Nested functions and classes are refactored as nodes of their own.
            if ($inner instanceof Expr\Closure || $inner instanceof Stmt\Function_ || $inner instanceof Stmt\ClassLike) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            if (!$inner instanceof BinaryOp\BooleanOr && !$inner instanceof BinaryOp\BooleanAnd) {
                return null;
            }

            $new = $this->chain($inner);
            $new === null or $changed = true;

            return $new;
        });

        return $changed ? $node : null;
    }

    /**
     * The operands of a chain of one operator, left to right: `||` and `&&` give a boolean and
     * evaluate left to right with short-circuit, so `a || (b || c)` is `a || b || c`.
     *
     * @param class-string<BinaryOp\BooleanOr|BinaryOp\BooleanAnd> $class
     * @return list<Expr>
     */
    private static function operands(Expr $expr, string $class): array
    {
        return $expr instanceof $class ? [...self::operands($expr->left, $class), ...self::operands($expr->right, $class)] : [$expr];
    }

    private static function literal(Expr $expr): bool
    {
        return $expr instanceof Scalar\String_ || $expr instanceof Scalar\Int_
            || ($expr instanceof Expr\UnaryMinus && $expr->expr instanceof Scalar\Int_);
    }

    private static function scope(Node $node): ?Scope
    {
        /** @var mixed $scope */
        $scope = $node->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope ? $scope : null;
    }

    /**
     * The chain with every long enough run replaced, or null when nothing changes.
     */
    private function chain(BinaryOp\BooleanOr|BinaryOp\BooleanAnd $chain): ?Expr
    {
        $or = $chain instanceof BinaryOp\BooleanOr;
        $operands = self::operands($chain, $or ? BinaryOp\BooleanOr::class : BinaryOp\BooleanAnd::class);
        $result = [];
        $changed = false;
        for ($i = 0, $n = \count($operands); $i < $n;) {
            $first = $this->comparison($operands[$i], $or);
            if ($first === null || !$this->safeSubject($first[0])) {
                $result[] = $operands[$i++];
                continue;
            }

            $items = [$first[1]];
            for ($j = $i + 1; $j < $n; ++$j) {
                $next = $this->comparison($operands[$j], $or);
                if ($next === null || !$this->nodeComparator->areNodesEqual($next[0], $first[0])) {
                    break;
                }

                $items[] = $next[1];
            }

            if (\count($items) < $this->minItems) {
                $result[] = $operands[$i++];
                continue;
            }

            $call = new Expr\FuncCall(new Name\FullyQualified('in_array'), [
                new Node\Arg($first[0]),
                new Node\Arg(new Expr\Array_(\array_map(static fn(Expr $item): Node\ArrayItem => new Node\ArrayItem($item), $items))),
                new Node\Arg(new Expr\ConstFetch(new Name('true'))),
            ]);
            $result[] = $or ? $call : new Expr\BooleanNot($call);
            $changed = true;
            $i = $j;
        }

        if (!$changed) {
            return null;
        }

        $expr = $result[0];
        foreach (\array_slice($result, 1) as $operand) {
            $expr = $or ? new BinaryOp\BooleanOr($expr, $operand) : new BinaryOp\BooleanAnd($expr, $operand);
        }

        return $expr;
    }

    /**
     * `subject === item` or `item === subject` (`!==` in an `&&` chain): [subject, item].
     *
     * @return array{Expr, Expr}|null
     */
    private function comparison(Expr $expr, bool $or): ?array
    {
        if (!($or ? $expr instanceof BinaryOp\Identical : $expr instanceof BinaryOp\NotIdentical)) {
            return null;
        }

        /** @var BinaryOp\Identical|BinaryOp\NotIdentical $expr */
        return match (true) {
            $this->item($expr->right) && !$this->item($expr->left) => [$expr->left, $expr->right],
            $this->item($expr->left) && !$this->item($expr->right) => [$expr->right, $expr->left],
            default => null,
        };
    }

    /**
     * An integer or string the compiler puts into the constant array as it is.
     */
    private function item(Expr $expr): bool
    {
        if (self::literal($expr)) {
            return true;
        }

        if (!$expr instanceof Expr\ClassConstFetch || !$expr->class instanceof Name || !$expr->name instanceof Node\Identifier
            || \strtolower($expr->name->toString()) === 'class'
        ) {
            return false;
        }

        $class = self::scope($expr)?->getClassReflection();
        $name = \strtolower((string) $this->getName($expr->class));
        if ($class === null || $class->isTrait() || ($name !== 'self' && $name !== \strtolower($class->getName()))
            || !$class->hasConstant($expr->name->toString())
        ) {
            return false;
        }

        $constant = $class->getConstant($expr->name->toString());

        return $constant->getDeclaringClass()->getName() === $class->getName() && self::literal($constant->getValueExpr());
    }

    /**
     * Reading the subject once gives what reading it up to N times gave, with the same warnings.
     */
    private function safeSubject(Expr $subject): bool
    {
        if ($subject instanceof Expr\Variable) {
            return \is_string($subject->name) && $subject->name !== 'this'
                && self::scope($subject)?->hasVariableType($subject->name)->yes() === true;
        }

        if ($subject instanceof Expr\PropertyFetch) {
            return $subject->var instanceof Expr\Variable
                && ($this->properties ??= new ExtractRepeatedPropertyFetchRector($this->reflectionResolver, $this->reflectionProvider))->safe($subject);
        }

        return $subject instanceof Expr\ArrayDimFetch
            && ($this->dims ??= new ExtractRepeatedArrayDimFetchRector($this->reflectionResolver))->safe($subject);
    }
}
