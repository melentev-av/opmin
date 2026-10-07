<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
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
 * - a readonly property may be read across calls (its value cannot change); any other property only
 *   while no user code may run between the reads, and only when it cannot be undefined (not public,
 *   never `unset()` in its class) — an undefined property warns on every read;
 * - no write to `$x->foo` and no reference to it between the reads.
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/ExtractRepeatedPropertyFetch')]
final class ExtractRepeatedPropertyFetchRector extends AbstractExtractRepeatedReadRector
{
    private ?\PhpParser\Parser $parser = null;

    public static function alias(): string
    {
        return 'property_fetch';
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

        return $class !== null && $class->hasNativeProperty($read->name->toString())
            && $class->getNativeProperty($read->name->toString())->isReadOnly();
    }

    public function name(Expr $read): string
    {
        return $read instanceof Expr\PropertyFetch && $read->name instanceof Node\Identifier ? $read->name->toString() : 'value';
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

    private function parser(): \PhpParser\Parser
    {
        return $this->parser ??= (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
    }
}
