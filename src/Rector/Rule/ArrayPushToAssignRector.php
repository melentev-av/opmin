<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\FunctionBody;
use Opmin\Rector\Support\GlobalFunctionCall;
use Opmin\Rector\Support\Reprint;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * `\array_push($a, $v);` → `$a[] = $v;`: the call becomes an append.
 *
 * Measured on PHP 8.1–8.5: one value −2 opcodes, two values −1, three 0, four +1 — so only one or
 * two values are rewritten.
 *
 * Fires only when the result is provably the same:
 * - the call is a statement of its own (its result, the new count, is not used), positional
 *   arguments without spread;
 * - the array is, by native type, an `array` (an `ArrayAccess` object would get `offsetSet()`):
 *   - a local variable that is surely defined (`[]=` creates an undefined one, `array_push()`
 *     throws) and never bound by reference;
 *   - or a declared property that is surely initialized — `array_push()` throws on an
 *     uninitialized one, `[]=` fills it: it has a default value, is not public, readonly or hooked,
 *     its class is final or it is private, and its class never unsets it; a static one is named by
 *     `self::` or its class;
 * - the values are not able to change the array: no assignment, increment, `eval` or `include`, no
 *   mention of a local array variable;
 * - with two values, `array_push()` evaluates both before appending, the appends do it in between:
 *   both values are literals or surely defined local variables (an undefined one warns, and an
 *   error handler that throws would see the first value appended).
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/ArrayPushToAssign')]
final class ArrayPushToAssignRector extends AbstractRector
{
    /** Values at which the appends still save opcodes (measured: 1 → −2, 2 → −1, 3 → 0). */
    private const int MAX_VALUES = 2;

    /** Attribute of a statement to replace: its appends, put by its function. */
    private const string APPENDS = 'opmin_array_push_appends';

    private ?\PhpParser\Parser $parser = null;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public static function alias(): string
    {
        return 'array_push';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Turn array_push() of one or two values into appends', [
            new ConfiguredCodeSample(
                '\array_push($items, $item);',
                '$items[] = $item;',
                [],
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Stmt\ClassMethod::class, Stmt\Function_::class, Expr\Closure::class, Stmt\Expression::class];
    }

    /**
     * A function marks its safe `array_push()` statements, then each is replaced on its own: Rector
     * keeps the layout around a statement it replaces with several.
     *
     * @param Stmt\ClassMethod|Stmt\Function_|Expr\Closure|Stmt\Expression $node
     * @return Node|non-empty-list<Stmt\Expression>|null
     */
    public function refactor(Node $node): Node|array|null
    {
        if ($node instanceof Stmt\Expression) {
            /** @var mixed $appends */
            $appends = $node->getAttribute(self::APPENDS);
            if (!\is_array($appends) || $appends === []) {
                return null;
            }

            $node->setAttribute(self::APPENDS, null);
            /** @var non-empty-list<Expr\Assign> $appends */
            $node->expr = \array_shift($appends);

            return $appends === [] ? $node : [$node, ...\array_map(static fn(Expr\Assign $a): Stmt\Expression => new Stmt\Expression($a), $appends)];
        }

        if ($node->stmts === null || !FunctionBody::allowsNewVariables($node) || FunctionBody::ignored($node, self::alias())) {
            return null;
        }

        $referenced = FunctionBody::referencedVariables($node);
        foreach (FunctionBody::ownNodes($node) as $stmt) {
            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\FuncCall) {
                $appends = $this->appends($stmt->expr, $referenced);
                $appends === null or $stmt->setAttribute(self::APPENDS, $appends);
            }
        }

        return null;
    }

    private static function scope(Node $node): ?Scope
    {
        /** @var mixed $scope */
        $scope = $node->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope ? $scope : null;
    }

    /**
     * A local variable that surely holds a value here and is never bound by reference.
     *
     * @param array<string, true> $referenced
     */
    private static function definedLocal(Expr $expr, array $referenced): bool
    {
        return $expr instanceof Expr\Variable && \is_string($expr->name) && $expr->name !== 'this'
            && !isset($referenced[$expr->name]) && self::scope($expr)?->hasVariableType($expr->name)->yes() === true;
    }

    /**
     * A copy of the array expression for each append.
     */
    private static function copy(Expr $target): Expr
    {
        return match (true) {
            $target instanceof Expr\Variable => new Expr\Variable($target->name),
            $target instanceof Expr\PropertyFetch => new Expr\PropertyFetch(self::copy($target->var), clone $target->name),
            $target instanceof Expr\StaticPropertyFetch => new Expr\StaticPropertyFetch(clone $target->class, clone $target->name),
            default => clone $target,
        };
    }

    /**
     * A literal or a surely defined local variable: evaluating it runs nothing and cannot fail.
     *
     * @param array<string, true> $referenced
     */
    private static function simple(Expr $value, array $referenced): bool
    {
        return $value instanceof Scalar\Int_ || $value instanceof Scalar\Float_ || $value instanceof Scalar\String_
            || ($value instanceof Expr\ConstFetch && \in_array($value->name->toLowerString(), ['true', 'false', 'null'], true))
            || ($value instanceof Expr\Variable && $value->name === 'this')
            || self::definedLocal($value, $referenced);
    }

    /**
     * `$a[] = $v` for each value of a safe `array_push()` statement.
     *
     * @param array<string, true> $referenced
     * @return non-empty-list<Expr\Assign>|null
     */
    private function appends(Expr\FuncCall $call, array $referenced): ?array
    {
        $args = $call->getArgs();
        if (!GlobalFunctionCall::plain($call, 'array_push') || \count($args) < 2 || \count($args) > self::MAX_VALUES + 1) {
            return null;
        }

        $target = $args[0]->value;
        $values = \array_map(static fn(Node\Arg $a): Expr => $a->value, \array_slice($args, 1));
        if (!($target instanceof Expr\Variable || $target instanceof Expr\PropertyFetch || $target instanceof Expr\StaticPropertyFetch)
            || self::scope($target)?->getNativeType($target)->isArray()->yes() !== true
            || !$this->safeTarget($target, $referenced)
        ) {
            return null;
        }

        $local = $target instanceof Expr\Variable && \is_string($target->name) ? $target->name : null;
        foreach ($values as $value) {
            if (!$this->harmless($value, $local) || (\count($values) > 1 && !self::simple($value, $referenced))) {
                return null;
            }
        }

        # A value printed as it was keeps no parentheses: `$x and $y` would assign `$x` only.
        foreach ($values as $value) {
            if (!Reprint::fits($value, Reprint::LOOSER_THAN_ASSIGN)) {
                return null;
            }
        }

        $appends = [];
        foreach ($values as $value) {
            Reprint::prepare($value, Reprint::LOOSER_THAN_ASSIGN);
            $appends[] = new Expr\Assign(new Expr\ArrayDimFetch(self::copy($target)), $value);
        }

        return $appends === [] ? null : $appends;
    }

    /**
     * @param array<string, true> $referenced
     */
    private function safeTarget(Expr\Variable|Expr\PropertyFetch|Expr\StaticPropertyFetch $target, array $referenced): bool
    {
        if ($target instanceof Expr\Variable) {
            return self::definedLocal($target, $referenced);
        }

        if ($target instanceof Expr\PropertyFetch) {
            if (!$target->name instanceof Node\Identifier
                || !(($target->var instanceof Expr\Variable && $target->var->name === 'this') || self::definedLocal($target->var, $referenced))
            ) {
                return false;
            }

            $class = $this->objectClass($target->var);

            return $class !== null && $this->initializedProperty($class, $target->name->toString(), false);
        }

        if (!$target->name instanceof Node\VarLikeIdentifier || !$target->class instanceof Name) {
            return false;
        }

        $scope = self::scope($target);
        $name = $target->class->toLowerString();
        if ($name === 'self') {
            $class = $scope?->getClassReflection();
        } elseif ($name === 'static' || $name === 'parent' || !$this->reflectionProvider->hasClass($target->class->toString())) {
            return false;
        } else {
            $class = $this->reflectionProvider->getClass($target->class->toString());
        }

        return $class !== null && $this->initializedProperty($class, $target->name->toString(), true);
    }

    /**
     * The class of `$this` or of a local variable known from native types, not nullable.
     */
    private function objectClass(Expr $var): ?ClassReflection
    {
        $scope = self::scope($var);
        if ($var instanceof Expr\Variable && $var->name === 'this') {
            return $scope?->getClassReflection();
        }

        $type = $scope?->getNativeType($var);
        if ($type === null || !$type->isObject()->yes() || !$type->isNull()->no()) {
            return null;
        }

        $classes = $type->getObjectClassReflections();

        return \count($classes) === 1 ? $classes[0] : null;
    }

    /**
     * A declared property that is initialized from the start and stays so: a default value, no
     * hooks, not readonly; an instance one is not public, private or of a final class, and never
     * unset in its class — so neither magic methods nor a subclass may take it away.
     */
    private function initializedProperty(ClassReflection $class, string $name, bool $static): bool
    {
        if (!$class->hasNativeProperty($name)) {
            return false;
        }

        $property = $class->getNativeProperty($name);
        if ($property->isStatic() !== $static || $property->isReadOnly() || $property->isHooked() || $property->isVirtual()->yes()
            || !$property->getNativeReflection()->hasDefaultValue()
        ) {
            return false;
        }

        if ($static) {
            return true;
        }

        return !$property->isPublic() && ($class->isFinal() || $property->isPrivate())
            && $property->getDeclaringTrait() === null && $property->getDeclaringClass()->getName() === $class->getName()
            && !$class->hasNativeMethod('__get') && !$this->unsetInClass($class, $name);
    }

    /**
     * Whether the class may `unset()` the property: an `unset()` of it (or of a dynamic name) in a
     * declaration of the class, or a file that cannot be read.
     */
    private function unsetInClass(ClassReflection $class, string $name): bool
    {
        $file = $class->getFileName();
        $code = $file === null ? false : @\file_get_contents($file);
        if ($code === false) {
            return true;
        }

        try {
            $stmts = ($this->parser ??= (new \PhpParser\ParserFactory())->createForNewestSupportedVersion())->parse($code) ?? [];
        } catch (\PhpParser\Error) {
            return true;
        }

        $finder = new NodeFinder();
        $short = $class->getNativeReflection()->getShortName();
        $found = false;
        foreach ($finder->findInstanceOf($stmts, Stmt\ClassLike::class) as $declaration) {
            if ($declaration->name === null || \strcasecmp($declaration->name->toString(), $short) !== 0) {
                continue;
            }

            $found = true;
            foreach ($finder->findInstanceOf($declaration->stmts, Stmt\Unset_::class) as $unset) {
                foreach ($unset->vars as $var) {
                    if ($var instanceof Expr\PropertyFetch && (!$var->name instanceof Node\Identifier || $var->name->toString() === $name)) {
                        return true;
                    }
                }
            }
        }

        return !$found;
    }

    /**
     * Evaluating the value writes nothing and does not touch the local array `$local`.
     */
    private function harmless(Expr $value, ?string $local): bool
    {
        return (new NodeFinder())->findFirst($value, static fn(Node $n): bool => $n instanceof Expr\Assign
            || $n instanceof Expr\AssignOp || $n instanceof Expr\AssignRef || $n instanceof Expr\PreInc
            || $n instanceof Expr\PostInc || $n instanceof Expr\PreDec || $n instanceof Expr\PostDec
            || $n instanceof Expr\Eval_ || $n instanceof Expr\Include_
            || ($local !== null && $n instanceof Expr\Variable && $n->name === $local)) === null;
    }
}
