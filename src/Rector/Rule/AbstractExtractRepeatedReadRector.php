<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\ArgumentPassing;
use Opmin\Rector\Support\FunctionBody;
use Opmin\Rector\Support\ReadEvent;
use Opmin\Rector\Support\ReadEventCollector;
use Opmin\Rector\Support\ReadSpec;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Type;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\Reflection\ReflectionResolver;

/**
 * Moves a repeated read into a local variable: `$foo = $x->foo;` before the statement of the first
 * read, `$foo` instead of the later reads (brief, «Свои Rector-правила»).
 *
 * The first read must run unconditionally and before anything that may change the value in its
 * statement: the assignment takes its place. The later reads are replaced while the value is
 * provably the same: up to a write to the expression or its base variable, and — unless the value is
 * {@see ReadSpec::stable()} — up to the first point where user code may run. Each statement list is
 * handled on its own, so the variable is always assigned on every path that reads it.
 *
 * @internal
 */
abstract class AbstractExtractRepeatedReadRector extends AbstractRector implements ConfigurableRectorInterface, ReadSpec
{
    public const string MIN_READS = 'min_reads';

    /**
     * Point where the extraction pays off: measured by `bin/bench` on PHP 8.1–8.5, the assignment
     * costs as much as one read, so 2 reads save 1 opcode, 3 reads — 2.
     */
    public const int DEFAULT_MIN_READS = 2;

    /** @var positive-int */
    protected int $minReads = self::DEFAULT_MIN_READS;

    /** @var array<string, true> Variables of the function bound by reference. */
    private array $referenced = [];

    public function __construct(
        protected readonly ReflectionResolver $reflectionResolver,
    ) {}

    /**
     * Short name for `#[\Opmin\Ignore(rules: [...])]` and `@opmin-ignore name`.
     *
     * @return non-empty-string
     */
    abstract public static function alias(): string;

    /**
     * @param array<array-key, mixed> $configuration `min_reads`: a positive integer or `auto`.
     */
    public function configure(array $configuration): void
    {
        $minReads = $configuration[self::MIN_READS] ?? 'auto';
        if ($minReads === 'auto' || $minReads === null) {
            $this->minReads = self::DEFAULT_MIN_READS;
            return;
        }

        \is_int($minReads) && $minReads > 0 or throw new \InvalidArgumentException(
            static::class . ': `min_reads` must be a positive integer or `auto`, got ' . \get_debug_type($minReads) . '.',
        );
        $this->minReads = $minReads;
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
        if ($node->stmts === null || !FunctionBody::allowsNewVariables($node) || FunctionBody::ignored($node, static::alias())) {
            return null;
        }

        $this->referenced = FunctionBody::referencedVariables($node);
        $taken = FunctionBody::variableNames($node);
        $changed = false;
        $node->stmts = $this->list($node->stmts, $taken, $changed);

        return $changed ? $node : null;
    }

    protected static function scope(Node $node): ?Scope
    {
        /** @var mixed $scope */
        $scope = $node->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope ? $scope : null;
    }

    /**
     * The value is never an object: no magic method or operator handler runs on it.
     */
    protected function neverObject(Expr $expr): bool
    {
        $type = $this->nativeType($expr);

        return $type !== null && !$type instanceof \PHPStan\Type\MixedType && $type->isObject()->no()
            && !$type->isIterable()->maybe();
    }

    /**
     * Reading the property runs no user code: it is declared, not static, without hooks, and the
     * class and its parents have no `__get()`.
     */
    protected function plainProperty(Expr\PropertyFetch $fetch): bool
    {
        if (!$fetch->name instanceof Node\Identifier) {
            return false;
        }

        $class = $this->objectClass($fetch->var);
        if ($class === null || $class->hasNativeMethod('__get') || !$class->hasNativeProperty($fetch->name->toString())) {
            return false;
        }

        $property = $class->getNativeProperty($fetch->name->toString());

        return !$property->isStatic() && !$property->isHooked() && !$property->isVirtual()->yes();
    }

    /**
     * The class of an object expression known from native types: `$this`, a typed parameter,
     * `new Foo()`. Null when it may be something else (null, a union, an unknown class).
     */
    protected function objectClass(Expr $expr): ?ClassReflection
    {
        if ($expr instanceof Expr\Variable && $expr->name === 'this') {
            return self::scope($expr)?->getClassReflection();
        }

        $type = $this->nativeType($expr);
        if ($type === null || !$type->isObject()->yes() || !$type->isNull()->no()) {
            return null;
        }

        $classes = $type->getObjectClassReflections();

        return \count($classes) === 1 ? $classes[0] : null;
    }

    protected function nativeType(Expr $expr): ?Type
    {
        return self::scope($expr)?->getNativeType($expr);
    }

    /**
     * Nodes with a statement list directly inside a statement.
     *
     * @return list<Stmt\If_|Stmt\ElseIf_|Stmt\Else_|Stmt\TryCatch|Stmt\Catch_|Stmt\Finally_|Stmt\Case_|Stmt\Foreach_|Stmt\For_|Stmt\While_|Stmt\Do_|Stmt\Block>
     */
    private static function innerLists(Stmt $stmt): array
    {
        return \array_values(match (true) {
            $stmt instanceof Stmt\If_ => [$stmt, ...$stmt->elseifs, ...($stmt->else === null ? [] : [$stmt->else])],
            $stmt instanceof Stmt\TryCatch => [$stmt, ...$stmt->catches, ...($stmt->finally === null ? [] : [$stmt->finally])],
            $stmt instanceof Stmt\Switch_ => $stmt->cases,
            $stmt instanceof Stmt\Foreach_, $stmt instanceof Stmt\For_, $stmt instanceof Stmt\While_,
            $stmt instanceof Stmt\Do_, $stmt instanceof Stmt\Block => [$stmt],
            default => [],
        });
    }

    /**
     * @param array<array-key, Stmt> $stmts
     * @param array<string, true> $taken
     * @return list<Stmt>
     */
    private function list(array $stmts, array &$taken, bool &$changed): array
    {
        $stmts = \array_values($stmts);
        # One group at a time: a rewrite changes the events of the rest of the list.
        for ($guard = 0; $guard < 50; ++$guard) {
            $group = $this->group($stmts);
            if ($group === null) {
                break;
            }

            [$index, $first, $reads] = $group;
            $name = FunctionBody::freeName($this->name($first), $taken);
            $taken[$name] = true;
            $replace = [];
            foreach ($reads as $read) {
                $replace[\spl_object_id($read)] = true;
            }

            $this->traverseNodesWithCallable(\array_slice($stmts, $index), static fn(Node $n): ?Node => isset($replace[\spl_object_id($n)]) ? new Expr\Variable($name) : null);
            $assign = new Stmt\Expression(new Expr\Assign(new Expr\Variable($name), $first));
            \array_splice($stmts, $index, 0, [$assign]);
            $changed = true;
        }

        foreach ($stmts as $stmt) {
            foreach (self::innerLists($stmt) as $owner) {
                $owner->stmts = $this->list($owner->stmts, $taken, $changed);
            }
        }

        return $stmts;
    }

    /**
     * The first group of reads worth a variable: [statement index, first read, all reads].
     *
     * @param list<Stmt> $stmts
     * @return array{int, Expr, non-empty-list<Expr>}|null
     */
    private function group(array $stmts): ?array
    {
        $collector = new ReadEventCollector(
            $this,
            fn(Expr $e): bool => $this->neverObject($e),
            fn(Expr\PropertyFetch $e): bool => $this->plainProperty($e),
            fn(Expr\CallLike $call, int $position): ?bool => (new ArgumentPassing($this->reflectionResolver))->byReference($call, $position),
        );
        $events = $collector->collect($stmts);
        $tried = [];
        foreach ($events as $i => $event) {
            if ($event->kind !== ReadEvent::READ || $event->conditional || $event->node === null
                || $event->key === null || $event->base === null || isset($tried[$event->key])
            ) {
                continue;
            }

            $tried[$event->key] = true;
            if (isset($this->referenced[$event->base]) || !$this->unaffectedBefore($events, $i) || !$this->safe($event->node)) {
                continue;
            }

            $stable = $this->stable($event->node);
            $reads = [$event->node];
            for ($j = $i + 1, $n = \count($events); $j < $n; ++$j) {
                $next = $events[$j];
                if ($this->stops($next, $event, $stable)) {
                    break;
                }

                $next->kind === ReadEvent::READ && $next->key === $event->key && $next->node !== null and $reads[] = $next->node;
            }

            if (\count($reads) >= $this->minReads) {
                return [$event->statement, $event->node, $reads];
            }
        }

        return null;
    }

    /**
     * Nothing earlier in the statement of the first read may change it: the assignment moves the
     * read before the whole statement.
     *
     * @param list<ReadEvent> $events
     */
    private function unaffectedBefore(array $events, int $first): bool
    {
        $read = $events[$first];
        for ($i = $first - 1; $i >= 0 && $events[$i]->statement === $read->statement; --$i) {
            if ($events[$i]->kind === ReadEvent::IMPURE || $this->stops($events[$i], $read, true)) {
                return false;
            }
        }

        return true;
    }

    private function stops(ReadEvent $event, ReadEvent $read, bool $stable): bool
    {
        return match ($event->kind) {
            ReadEvent::OPAQUE => true,
            ReadEvent::IMPURE => !$stable,
            ReadEvent::WRITE => $event->key === $read->key || ($event->key === null && $event->base === $read->base),
            ReadEvent::BASE_WRITE => $event->base === null || $event->base === $read->base,
            default => false,
        };
    }
}
