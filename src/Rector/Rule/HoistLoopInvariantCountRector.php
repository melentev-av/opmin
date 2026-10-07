<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Module\Analysis\Shadow\ShadowIndex;
use Opmin\Rector\Support\ArgumentPassing;
use Opmin\Rector\Support\FunctionBody;
use Opmin\Rector\Support\ReadEvent;
use Opmin\Rector\Support\ReadEventCollector;
use Opmin\Rector\Support\ReadSpec;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
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
 * `for ($i = 0; $i < count($a); $i++)` → `for ($i = 0, $n = count($a); $i < $n; $i++)`: the count
 * is computed once instead of on every iteration (brief, rule 3).
 *
 * The static count stays the same at best (measured by `bin/bench`: 0 with `\count`), the executed
 * opcodes drop N times: the pipeline accepts this rule when `ops_opt` does not grow
 * ({@see self::EXECUTED_GAIN}).
 *
 * Fires only when `$a` is a local variable whose native type is an array (`Countable::count()` may
 * have its own logic), not bound by reference, nothing in the loop writes to it or may pass it by
 * reference, and `count` is the global function (brief 2.3).
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/HoistLoopInvariantCount')]
final class HoistLoopInvariantCountRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string SHADOWS = 'shadows';

    /** The gain is in executed opcodes, not in static ones: accepted when the static count does not grow. */
    public const bool EXECUTED_GAIN = true;

    private ShadowIndex $shadows;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ReflectionResolver $reflectionResolver,
    ) {
        $this->shadows = new ShadowIndex();
    }

    public static function alias(): string
    {
        return 'count';
    }

    /**
     * @param array<array-key, mixed> $configuration `shadows`: JSON file (or array) of {@see ShadowIndex::toArray()}.
     */
    public function configure(array $configuration): void
    {
        /** @var mixed $shadows */
        $shadows = $configuration[self::SHADOWS] ?? null;
        if (\is_string($shadows)) {
            $raw = @\file_get_contents($shadows);
            $raw === false and throw new \InvalidArgumentException(self::class . ": cannot read `{$shadows}`.");
            /** @var mixed $shadows */
            $shadows = \json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        }

        \is_array($shadows) and $this->shadows = ShadowIndex::fromArray($shadows);
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Compute the count of a loop-invariant array once', [
            new ConfiguredCodeSample(
                'for ($i = 0; $i < \count($items); $i++) { $sum += $items[$i]; }',
                'for ($i = 0, $n = \count($items); $i < $n; $i++) { $sum += $items[$i]; }',
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
        if ($node->stmts === null || !FunctionBody::allowsNewVariables($node) || FunctionBody::ignored($node, self::alias())) {
            return null;
        }

        $referenced = FunctionBody::referencedVariables($node);
        $taken = FunctionBody::variableNames($node);
        $changed = false;
        foreach (FunctionBody::ownNodes($node) as $loop) {
            if (!$loop instanceof Stmt\For_ || \count($loop->cond) !== 1 || !$loop->cond[0] instanceof Expr\BinaryOp) {
                continue;
            }

            $cond = $loop->cond[0];
            foreach (['left', 'right'] as $side) {
                $count = $side === 'left' ? $cond->left : $cond->right;
                $array = $count instanceof Expr\FuncCall ? $this->countedArray($count) : null;
                if ($count instanceof Expr\FuncCall && $array !== null && !isset($referenced[$array]) && $this->invariant($loop, $array)) {
                    $name = FunctionBody::freeName('n', $taken);
                    $taken[$name] = true;
                    $loop->init[] = new Expr\Assign(new Expr\Variable($name), $count);
                    $side === 'left' ? $cond->left = new Expr\Variable($name) : $cond->right = new Expr\Variable($name);
                    $changed = true;
                    break;
                }
            }
        }

        return $changed ? $node : null;
    }

    /**
     * `count($a)` of the global function with one argument, a local array: the name of `$a`.
     */
    private function countedArray(Expr\FuncCall $call): ?string
    {
        if (!$call->name instanceof Name || \strtolower($call->name->getLast()) !== 'count' || $call->isFirstClassCallable()
            || \count($call->getArgs()) !== 1
        ) {
            return null;
        }

        $arg = $call->getArgs()[0];
        $var = $arg->value;
        if ($arg->unpack || $arg->name !== null || !$var instanceof Expr\Variable || !\is_string($var->name) || $var->name === 'this') {
            return null;
        }

        /** @var mixed $scope */
        $scope = $var->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope || !$scope->getNativeType($var)->isArray()->yes() || !$this->globalCount($call->name, $scope)) {
            return null;
        }

        return $var->name;
    }

    private function globalCount(Name $name, Scope $scope): bool
    {
        if ($name->isFullyQualified() || $name->getAttribute('resolvedName') !== null) {
            return $name->isFullyQualified() || \strtolower((string) $name->getAttribute('resolvedName')) === 'count';
        }

        $namespace = (string) $scope->getNamespace();

        return $namespace === '' || ($this->shadows->canQualifyFunction($namespace, 'count')
            && !$this->reflectionProvider->hasFunction(new Name\FullyQualified($namespace . '\count'), null));
    }

    /**
     * Nothing in the loop (condition, step, body) writes `$a`, passes it by reference or is opaque.
     */
    private function invariant(Stmt\For_ $loop, string $array): bool
    {
        $collector = new ReadEventCollector(
            new class implements ReadSpec {
                public function match(Expr $expr): ?array
                {
                    return null;
                }

                public function safe(Expr $read): bool
                {
                    return false;
                }

                public function stable(Expr $read): bool
                {
                    return false;
                }

                public function name(Expr $read): string
                {
                    return 'value';
                }
            },
            static fn(Expr $e): bool => false,
            static fn(Expr\PropertyFetch $e): bool => false,
            fn(Expr\CallLike $call, int $position): ?bool => (new ArgumentPassing($this->reflectionResolver))->byReference($call, $position),
        );
        $body = [...$loop->stmts, ...\array_map(static fn(Expr $e): Stmt => new Stmt\Expression($e), [...$loop->cond, ...$loop->loop])];
        foreach ($collector->collect($body) as $event) {
            if ($event->kind === ReadEvent::OPAQUE
                || ($event->kind === ReadEvent::BASE_WRITE && ($event->base === null || $event->base === $array))
            ) {
                return false;
            }
        }

        return true;
    }
}
