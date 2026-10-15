<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Module\Analysis\FileReferences;
use Opmin\Module\Analysis\PropertyWrites;
use Opmin\Module\Analysis\ReferenceCollector;
use Opmin\Rector\Support\ArgumentPassing;
use Opmin\Rector\Support\StableProperties;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Reflection\ReflectionResolver;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Moves a repeated read of a property into a local variable: every `$x->foo` is a `FETCH_OBJ_R`,
 * the variable is a CV (brief, rule 1).
 *
 * Fires only when the reads provably return the same value without running user code:
 * - `$x` is `$this` or a local variable of a known class (native types), not bound by reference,
 *   not reassigned between the reads;
 * - the property is declared and not static, without hooks; the class and its parents have no
 *   `__get()`, and a subclass cannot add one to it: the class is final or the property private;
 * - a readonly property may be read across calls (its value cannot change), and so may a stable one
 *   (below); any other property only while no user code may run between the reads, and only when it
 *   cannot be undefined (not public, never `unset()` in its class) — an undefined property warns on
 *   every read;
 * - no write to `$x->foo` and no reference to it between the reads.
 *
 * A stable property (plan, L1 of the model of call effects) is private, declared in the class of
 * the method itself (not in a trait, the class uses no trait and has no `__get()`/`__set()`), and
 * only the initializers of its class write it, as `$this->foo` ({@see StableProperties}); in the
 * whole project ({@see self::EFFECTS}) no closure writes or references a property of that name (it
 * may be bound to the class), no initializer is called again on an existing object, no object is
 * walked like an array (`array_walk()`, `new ArrayObject($object)`), and no reflection writes a
 * property of the class. Without the project data no property is stable. `protected` and `public`
 * properties need a closed world (code outside the project may write them) and are not handled.
 *
 * Options:
 * - `min_reads` ({@see AbstractExtractRepeatedReadRector::MIN_READS});
 * - `effects`: JSON file with {@see \Opmin\Module\Analysis\ReferenceIndex::effects()} of the project.
 *
 * Not final: the tests fix the options in a subclass (testo/bridge-rector passes none).
 *
 * @internal
 * @psalm-suppress ClassMustBeFinal
 */
#[TestRectorFixtures('Fixture/ExtractRepeatedPropertyFetch')]
class ExtractRepeatedPropertyFetchRector extends AbstractExtractRepeatedReadRector
{
    public const string EFFECTS = 'effects';

    /**
     * Experimental: the project is all the code there is (an application, not a library) — a
     * `protected` or `public` property is stable when nothing in the project writes a property of
     * that name outside the initializers and no project class with `__get()`… extends its class.
     */
    public const string CLOSED_WORLD = 'closed_world';

    /** Options that hold a path to a JSON file: the rule's cache is keyed on the file's content. */
    public const array FILE_OPTIONS = [self::EFFECTS];

    private ?\PhpParser\Parser $parser = null;

    /** What may write a property bypassing its class in the project; null — unknown, nothing is stable. */
    private ?FileReferences $effects = null;

    /** @var array<string, array<non-empty-string, true>> Stable properties by file and class. */
    private array $stable = [];

    private bool $closedWorld = false;

    public function __construct(
        ReflectionResolver $reflectionResolver,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        parent::__construct($reflectionResolver);
    }

    public static function alias(): string
    {
        return 'property_fetch';
    }

    /**
     * @param array<array-key, mixed> $configuration {@see AbstractExtractRepeatedReadRector::configure()},
     *        `effects`: a path to the JSON file or the array itself (tests).
     */
    public function configure(array $configuration): void
    {
        parent::configure($configuration);
        $this->closedWorld = ($configuration[self::CLOSED_WORLD] ?? false) === true;
        /** @var mixed $effects */
        $effects = $configuration[self::EFFECTS] ?? null;
        if ($effects === null) {
            $this->effects = null;
            return;
        }

        if (\is_string($effects)) {
            $json = @\file_get_contents($effects);
            $json === false and throw new \InvalidArgumentException(self::class . ": cannot read `effects` file {$effects}.");
            /** @var mixed $effects */
            $effects = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        }

        \is_array($effects) or throw new \InvalidArgumentException(self::class . ': `effects` must be a path or an array.');
        $this->effects = FileReferences::fromArray($effects + (new FileReferences())->toArray());
        $this->stable = [];
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Move a repeated property read into a local variable', [
            new ConfiguredCodeSample(
                <<<'PHP'
                    $this->a = $this->config->a;
                    $this->b = $this->config->b;
                    PHP,
                <<<'PHP'
                    $config = $this->config;
                    $this->a = $config->a;
                    $this->b = $config->b;
                    PHP,
                [self::MIN_READS => 2],
            ),
        ]);
    }

    public function match(Expr $expr): ?array
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Node\Identifier
            || !$expr->var instanceof Expr\Variable || !\is_string($expr->var->name) || $expr->var->name === ''
        ) {
            return null;
        }

        return ['$' . $expr->var->name . '->' . $expr->name->toString(), $expr->var->name];
    }

    public function safe(Expr $read): bool
    {
        if (!$read instanceof Expr\PropertyFetch || !$read->name instanceof Node\Identifier || !$this->plainProperty($read)) {
            return false;
        }

        $class = $this->objectClass($read->var);
        if ($class === null) {
            return false;
        }

        $property = $class->getNativeProperty($read->name->toString());
        # A subclass of a non-final class may unset an inherited property and add `__get()` (lazy
        # proxies do exactly that): then every read reaches `__get()`.
        if ($this->closedWorld) {
            return !$this->magicSubclass($property->getDeclaringClass())
                && ($property->isReadOnly() || $this->surelyInitialized($class, $read->name->toString()) || $this->untouched($read->name->toString()));
        }

        if (!$class->isFinal() && !$property->isPrivate()) {
            return false;
        }

        return $property->isReadOnly() || $this->surelyInitialized($class, $read->name->toString());
    }

    public function stable(Expr $read): bool
    {
        if (!$read instanceof Expr\PropertyFetch || !$read->name instanceof Node\Identifier) {
            return false;
        }

        $class = $this->objectClass($read->var);
        if ($class === null || !$class->hasNativeProperty($read->name->toString())) {
            return false;
        }

        return $class->getNativeProperty($read->name->toString())->isReadOnly() || $this->encapsulated($read);
    }

    public function location(Expr $read): ?string
    {
        if (!$read instanceof Expr\PropertyFetch || !$read->name instanceof Node\Identifier) {
            return null;
        }

        $class = $this->objectClass($read->var);
        $name = $read->name->toString();
        if ($class === null || !$class->hasNativeProperty($name)) {
            return null;
        }

        return \strtolower($class->getNativeProperty($name)->getDeclaringClass()->getName()) . '::$' . $name;
    }

    public function name(Expr $read): string
    {
        return $read instanceof Expr\PropertyFetch && $read->name instanceof Node\Identifier ? $read->name->toString() : 'value';
    }

    protected function unlockedByEncapsulation(Expr $read): bool
    {
        if (!$read instanceof Expr\PropertyFetch || !$read->name instanceof Node\Identifier) {
            return false;
        }

        $class = $this->objectClass($read->var);

        return $class !== null && $class->hasNativeProperty($read->name->toString())
            && !$class->getNativeProperty($read->name->toString())->isReadOnly() && $this->encapsulated($read);
    }

    protected function unsafeReason(Expr $read): string
    {
        if (!$read instanceof Expr\PropertyFetch || !$read->name instanceof Node\Identifier) {
            return 'dynamic_name';
        }

        $class = $this->objectClass($read->var);
        if ($class === null) {
            return 'unknown_class';
        }

        if (!$this->plainProperty($read)) {
            return 'magic_or_undeclared';
        }

        $property = $class->getNativeProperty($read->name->toString());

        return match (true) {
            !$class->isFinal() && !$property->isPrivate() => ($property->isPublic() ? 'public' : 'protected') . '_in_open_class',
            $property->isPublic() => 'public',
            $property->getDeclaringTrait() !== null => 'trait',
            $property->getDeclaringClass()->getName() !== $class->getName() => 'inherited',
            default => 'unset_in_class',
        };
    }

    /**
     * Closed world: nothing in the project writes, references or unsets a property of the name
     * other than as `$this->name` in an initializer.
     */
    private function untouched(string $name): bool
    {
        return $this->effects !== null
            && !\in_array($name, $this->effects->outsideWrites, true) && !\in_array(PropertyWrites::ANY, $this->effects->outsideWrites, true)
            && !\in_array($name, $this->effects->closureWrites, true) && !\in_array(PropertyWrites::ANY, $this->effects->closureWrites, true);
    }

    /**
     * Closed world: a project class with `__get()`… is the class itself, a parent or a subclass.
     */
    private function magicSubclass(ClassReflection $class): bool
    {
        if ($this->effects === null) {
            return true;
        }

        foreach ($this->effects->magicClasses as $name) {
            if (!$this->reflectionProvider->hasClass($name)) {
                return true;
            }

            $magic = $this->reflectionProvider->getClass($name);
            if ($magic->getName() === $class->getName() || $magic->isSubclassOfClass($class) || $class->isSubclassOfClass($magic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A mutable property that cannot become undefined: an `unset()` one makes every read warn
     * (untyped) on its own. It is not public (nothing outside may unset it), declared in the class
     * itself, and the class never unsets it. An uninitialized typed property needs no guarantee:
     * without `__get()` its first read throws, before and after the change alike.
     */
    private function surelyInitialized(ClassReflection $class, string $name): bool
    {
        $property = $class->getNativeProperty($name);
        if ($property->isPublic() || $property->getDeclaringTrait() !== null
            || $property->getDeclaringClass()->getName() !== $class->getName()
        ) {
            return false;
        }

        $file = $class->getFileName();
        $code = $file === null ? false : @\file_get_contents($file);
        if ($code === false) {
            return false;
        }

        try {
            $stmts = $this->parser()->parse($code) ?? [];
        } catch (\PhpParser\Error) {
            return false;
        }

        $finder = new NodeFinder();
        $short = $class->getNativeReflection()->getShortName();
        $declarations = \array_filter(
            $finder->findInstanceOf($stmts, Stmt\ClassLike::class),
            static fn(Stmt\ClassLike $c): bool => $c->name !== null && \strcasecmp($c->name->toString(), $short) === 0,
        );
        if ($declarations === []) {
            return false;
        }

        foreach ($declarations as $declaration) {
            foreach ($finder->findInstanceOf($declaration->stmts, Stmt\Unset_::class) as $unset) {
                foreach ($unset->vars as $var) {
                    if ($var instanceof Expr\PropertyFetch && (!$var->name instanceof Node\Identifier || $var->name->toString() === $name)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * The property is stable: only the initializers of its class write it ({@see self::EFFECTS}).
     */
    private function encapsulated(Expr\PropertyFetch $read): bool
    {
        $class = $this->objectClass($read->var);
        $scope = self::scope($read)?->getClassReflection();
        if ($this->effects === null || !$read->name instanceof Node\Identifier || $class === null || $scope === null
            || !$class->hasNativeProperty($read->name->toString())
        ) {
            return false;
        }

        $name = $read->name->toString();
        $property = $class->getNativeProperty($name);
        $declaring = $property->getDeclaringClass();
        if ($this->closedWorld && !$property->isPrivate()) {
            return !$property->isStatic() && !$property->isHooked() && !$property->isVirtual()->yes()
                && $this->untouched($name) && !$this->magicSubclass($declaring) && $this->globallyStable($declaring);
        }
        if (!$property->isPrivate() || $property->isStatic() || $property->isHooked() || $property->isVirtual()->yes()
            || $property->getDeclaringTrait() !== null || $declaring->getName() !== $scope->getName() || $declaring->isAnonymous()
        ) {
            return false;
        }

        $key = $this->file->getFilePath() . "\0" . $declaring->getName();
        $this->stable[$key] ??= $this->stableProperties($declaring);

        return isset($this->stable[$key][$name]);
    }

    /**
     * Closed world: no fact of the project writes the properties of the class bypassing it.
     */
    private function globallyStable(ClassReflection $class): bool
    {
        $facts = $this->effects;
        if ($facts === null) {
            return false;
        }

        $local = $this->fileReferences($class->getFileName());
        $local === null or $facts = $facts->effectsWith($local);

        return !$facts->reinitializes && !$facts->walksObjects
            && !($facts->reflectionWrites && $this->reflected($class, $facts->reflectedClasses));
    }

    /**
     * @return array<non-empty-string, true>
     */
    private function stableProperties(ClassReflection $class): array
    {
        if ($this->effects === null || $class->hasNativeMethod('__get') || $class->hasNativeMethod('__set')
            || $class->getTraits() !== []
        ) {
            return [];
        }

        $local = $this->fileReferences($class->getFileName());
        if ($local === null) {
            return [];
        }

        $facts = $this->effects->effectsWith($local);
        if ($facts->reinitializes || $facts->walksObjects || ($facts->reflectionWrites && $this->reflected($class, $facts->reflectedClasses))) {
            return [];
        }

        $node = null;
        foreach ((new NodeFinder())->findInstanceOf($this->file->getNewStmts(), Stmt\Class_::class) as $candidate) {
            $this->isName($candidate, $class->getName()) and $node = $candidate;
        }

        if ($node === null) {
            return [];
        }

        $unstable = StableProperties::unstable(
            $node,
            fn(Expr\CallLike $call, int $position): ?bool => (new ArgumentPassing($this->reflectionResolver))->byReference($call, $position),
            fn(Expr $e): bool => $this->nativeType($e)?->isArray()->yes() === true,
        );
        foreach ($facts->closureWrites as $name) {
            $unstable[$name] = true;
        }

        if (isset($unstable[PropertyWrites::ANY])) {
            return [];
        }

        $stable = [];
        foreach ($class->getNativeReflection()->getProperties() as $property) {
            $name = $property->getName();
            $property->isPrivate() && !isset($unstable[$name]) and $stable[$name] = true;
        }

        return $stable;
    }

    /**
     * Reflection of the class or a subclass reaches its private properties; `*` — an unknown class.
     *
     * @param list<lowercase-string> $reflected
     */
    private function reflected(ClassReflection $class, array $reflected): bool
    {
        foreach ($reflected as $name) {
            if ($name === FileReferences::ANY_CLASS || $name === \strtolower($class->getName())
                || !$this->reflectionProvider->hasClass($name)
                || $this->reflectionProvider->getClass($name)->isSubclassOfClass($class)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the file of the class itself does to properties ({@see ReferenceCollector}); null when it
     * cannot be read.
     */
    private function fileReferences(?string $file): ?FileReferences
    {
        $code = $file === null ? false : @\file_get_contents($file);
        if ($code === false) {
            return null;
        }

        try {
            return (new ReferenceCollector())->collect($this->parser()->parse($code) ?? []);
        } catch (\PhpParser\Error) {
            return null;
        }
    }

    private function parser(): \PhpParser\Parser
    {
        return $this->parser ??= (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
    }
}
