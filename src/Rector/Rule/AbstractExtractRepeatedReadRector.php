<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Rector\Support\ArgumentPassing;
use Opmin\Rector\Support\FunctionBody;
use Opmin\Rector\Support\PureFunctionCall;
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
 * With {@see self::DECLINE_LOG} in the environment every group of reads that could pay off but is
 * given up is logged with the event that stopped it (`bin/declines` sums the log up): the measure
 * for what an analysis of call effects would unlock.
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

    /**
     * Environment variable with a file to append a JSON line to for every group of reads that could
     * pay off: given up (`reason` — the event that stopped it) or extracted (`reason: extracted`).
     */
    public const string DECLINE_LOG = 'OPMIN_EXTRACT_DECLINES';

    private const string EXTRACTED_FROM = 'opmin_extracted_from';

    /** @var positive-int */
    protected int $minReads = self::DEFAULT_MIN_READS;

    /** The decline log file; '' — off, null — not read yet. */
    private static ?string $declineLog = null;

    /** @var array<int, true> First reads already logged in this function. */
    private array $logged = [];

    private string $function = '';

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
        $this->logged = [];
        $this->function = $node instanceof Expr\Closure ? '{closure}' : (string) $node->name;
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
     * Why {@see ReadSpec::safe()} refuses the read, for the decline log.
     *
     * @return non-empty-string
     */
    protected function unsafeReason(Expr $read): string
    {
        return 'other';
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
        # A variable put in by an earlier extraction has no scope yet: it holds the read it replaced.
        /** @var mixed $read */
        $read = $expr->getAttribute(self::EXTRACTED_FROM);
        $read instanceof Expr and $expr = $read;

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

            $attributes = [self::EXTRACTED_FROM => $first];
            $this->traverseNodesWithCallable(\array_slice($stmts, $index), static fn(Node $n): ?Node => isset($replace[\spl_object_id($n)]) ? new Expr\Variable($name, $attributes) : null);
            $assign = new Stmt\Expression(new Expr\Assign(new Expr\Variable($name, $attributes), $first));
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
            fn(Expr\FuncCall $call): bool => (new PureFunctionCall($this->reflectionResolver, $this->nativeType(...)))->pure($call),
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
            if (isset($this->referenced[$event->base])) {
                $this->logGroup($events, $i, 'referenced');
                continue;
            }

            $before = $this->affectedBefore($events, $i);
            if ($before !== null) {
                $this->logGroup($events, $i, 'before:' . ($before->cause ?? $before->kind));
                continue;
            }

            if (!$this->safe($event->node)) {
                $this->logGroup($events, $i, 'unsafe:' . $this->unsafeReason($event->node));
                continue;
            }

            $stable = $this->stable($event->node);
            $reads = [$event->node];
            $stop = null;
            $crossed = false;
            for ($j = $i + 1, $n = \count($events); $j < $n; ++$j) {
                $next = $events[$j];
                if ($this->stops($next, $event, $stable)) {
                    $stop = $next;
                    break;
                }

                $crossed = $crossed || $next->kind === ReadEvent::IMPURE;
                $next->kind === ReadEvent::READ && $next->key === $event->key && $next->node !== null and $reads[] = $next->node;
            }

            if (\count($reads) >= $this->minReads) {
                $this->logGroup($events, $i, 'extracted', ['reads' => \count($reads), 'across_calls' => $crossed]);
                return [$event->statement, $event->node, $reads];
            }

            $this->logGroup($events, $i, $stop === null ? 'unknown' : ($stop->cause ?? $stop->kind));
        }

        return null;
    }

    /**
     * The event earlier in the statement of the first read that may change it, null when none: the
     * assignment moves the read before the whole statement.
     *
     * @param list<ReadEvent> $events
     */
    private function affectedBefore(array $events, int $first): ?ReadEvent
    {
        $read = $events[$first];
        for ($i = $first - 1; $i >= 0 && $events[$i]->statement === $read->statement; --$i) {
            if ($events[$i]->kind === ReadEvent::IMPURE || $this->stops($events[$i], $read, true)) {
                return $events[$i];
            }
        }

        return null;
    }

    /**
     * Logs a group of reads to {@see self::DECLINE_LOG}, once per first read, when it is long enough
     * to pay off.
     *
     * @param list<ReadEvent> $events
     * @param non-empty-string $reason
     * @param array<string, mixed> $extra
     */
    private function logGroup(array $events, int $first, string $reason, array $extra = []): void
    {
        self::$declineLog ??= \is_string($path = \getenv(self::DECLINE_LOG)) ? $path : '';
        $read = $events[$first];
        if (self::$declineLog === '' || $read->node === null || isset($this->logged[\spl_object_id($read->node)])) {
            return;
        }

        $this->logged[\spl_object_id($read->node)] = true;
        # The events between the first and the last read that stop a mutable value: which ones a
        # finer effect analysis would have to see through.
        $total = 0;
        $between = $pending = [];
        foreach (\array_slice($events, $first + 1) as $event) {
            if ($event->kind === ReadEvent::READ && $event->key === $read->key) {
                ++$total;
                $between += $pending;
                $pending = [];
            } elseif ($this->stops($event, $read, false)) {
                $pending[$event->cause ?? $event->kind] = true;
            }
        }

        ++$total;

        if ($total < $this->minReads) {
            return;
        }

        $record = [
            'rule' => static::alias(),
            'file' => $this->file->getFilePath(),
            'function' => $this->function,
            'key' => $read->key,
            'reason' => $reason,
            'total' => $total,
            'between' => \array_keys($between),
        ] + $extra;
        \file_put_contents(
            self::$declineLog,
            \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR) . "\n",
            \FILE_APPEND | \LOCK_EX,
        );
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
