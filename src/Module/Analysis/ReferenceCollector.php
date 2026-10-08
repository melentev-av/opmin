<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the {@see FileReferences} of a file: everything that can call or inspect a function
 * without a static call site, so the called side gets {@see Flag::CalledDynamically} or
 * {@see Flag::Reflection}.
 *
 * Over-approximates on purpose: a string that only looks like a callable still protects the function.
 *
 * @internal
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    /** @var array<lowercase-string, int<0, max>> Functions taking a callable, with its position: a non-literal one is a dynamic call. */
    private const CALLABLE_FUNCTIONS = [
        'call_user_func' => 0, 'call_user_func_array' => 0, 'forward_static_call' => 0,
        'forward_static_call_array' => 0, 'is_callable' => 0, 'array_map' => 0, 'array_filter' => 1, 'array_walk' => 1,
        'array_walk_recursive' => 1, 'array_reduce' => 1, 'usort' => 1, 'uasort' => 1, 'uksort' => 1,
        'iterator_apply' => 1, 'register_shutdown_function' => 0, 'spl_autoload_register' => 0,
        'set_error_handler' => 0, 'set_exception_handler' => 0, 'preg_replace_callback' => 1, 'ob_start' => 0,
    ];

    /** @var array<lowercase-string, true> */
    private array $functions = [];

    /** @var array<lowercase-string, true> */
    private array $methods = [];

    private bool $dynamicFunctionCalls = false;

    /** @var array<lowercase-string, true> */
    private array $dynamicMethodScopes = [];

    /** @var array<lowercase-string, true> */
    private array $reflectedClasses = [];

    /** @var list<lowercase-string|null> Enclosing classes, null for an anonymous one. */
    private array $classes = [];

    /**
     * @param array<array-key, Node> $stmts Parsed, without name resolution.
     */
    public function collect(array $stmts): FileReferences
    {
        $this->functions = $this->methods = $this->dynamicMethodScopes = $this->reflectedClasses = [];
        $this->dynamicFunctionCalls = false;
        $this->classes = [];
        (new NodeTraverser(new NameResolver(), $this))->traverse($stmts);

        return new FileReferences(
            functions: self::sorted($this->functions),
            methods: self::sorted($this->methods),
            dynamicFunctionCalls: $this->dynamicFunctionCalls,
            dynamicMethodScopes: self::sorted($this->dynamicMethodScopes),
            reflectedClasses: self::sorted($this->reflectedClasses),
        );
    }

    public function enterNode(Node $node): null
    {
        match (true) {
            $node instanceof Stmt\ClassLike => $this->classes[] = $node->namespacedName?->toLowerString(),
            $node instanceof String_ => $this->string($node->value),
            $node instanceof Expr\Array_ => $this->arrayCallable($node),
            $node instanceof Expr\FuncCall => $this->funcCall($node),
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => $this->methodCall($node->var, $node->name, $node->isFirstClassCallable()),
            $node instanceof Expr\StaticCall => $this->staticCall($node),
            $node instanceof Expr\New_ => $this->new($node),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $node instanceof Stmt\ClassLike and \array_pop($this->classes);

        return null;
    }

    /**
     * @return lowercase-string
     */
    private static function functionName(Name $name): string
    {
        /** @var Name|null $namespaced */
        $namespaced = $name->getAttribute('namespacedName');

        # An unqualified call in a namespace may resolve to either function: protect both.
        return \strtolower(($namespaced ?? $name)->toString());
    }

    /**
     * @param array<lowercase-string, true> $set
     * @return list<lowercase-string>
     */
    private static function sorted(array $set): array
    {
        $list = \array_map(strval(...), \array_keys($set));
        \sort($list, \SORT_STRING);

        /** @var list<lowercase-string> */
        return $list;
    }

    /**
     * `'App\fn'`, `'App\Foo::bar'`, `'App\Foo@bar'` (Laravel); a bare `'fn'` names a global function.
     */
    private function string(string $value): void
    {
        $value = \ltrim($value, '\\');
        if (\preg_match('/^([A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*)(?:(?:::|@)([A-Za-z_\x80-\xff][\w\x80-\xff]*))?$/', $value, $m) !== 1) {
            return;
        }

        if (isset($m[2])) {
            $this->methods[\strtolower($m[1] . '::' . $m[2])] = true;
            return;
        }

        $this->functions[\strtolower($m[1])] = true;
    }

    /**
     * `[$obj, 'method']`, `[Foo::class, 'method']`, `['Foo', 'method']`; `[$obj, $name]` is a dynamic call.
     */
    private function arrayCallable(Expr\Array_ $node): void
    {
        if (\count($node->items) !== 2) {
            return;
        }

        [$target, $method] = $node->items;
        if ($target === null || $method === null || $target->key !== null || $method->key !== null) {
            return;
        }

        if ($method->value instanceof String_) {
            $this->methods[$this->classOf($target->value) . '::' . \strtolower($method->value->value)] = true;
            return;
        }

        # Only when the first element can be an object or a class: `[$a, $b]` is usually just data.
        $method->value instanceof Expr\Variable && (
            $target->value instanceof Expr\Variable && $target->value->name === 'this'
            || $target->value instanceof Expr\ClassConstFetch
            || $target->value instanceof Expr\New_
        ) and $this->dynamicMethodScopes[$this->scopeOf($target->value)] = true;
    }

    private function funcCall(Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            # `$fn()`, `($this->handler)()`.
            $this->dynamicFunctionCalls = true;
            $this->dynamicMethodScopes[FileReferences::ANY_CLASS] = true;
            return;
        }

        if ($node->isFirstClassCallable()) {
            $this->functions[self::functionName($node->name)] = true;
            $this->functions[\strtolower(\ltrim($node->name->toString(), '\\'))] = true;
        }

        $name = DynamicScopeDetector::globalFunction($node->name);
        $position = $name === null ? null : (self::CALLABLE_FUNCTIONS[$name] ?? null);
        if ($position === null || $node->isFirstClassCallable()) {
            return;
        }

        $callable = $node->getArgs()[$position]->value ?? null;
        # A callable that is neither a literal, an array callable nor a closure.
        if ($callable instanceof Expr\Variable
            || $callable instanceof Expr\PropertyFetch
            || $callable instanceof Expr\StaticPropertyFetch
            || $callable instanceof Expr\ArrayDimFetch
            || $callable instanceof Expr\BinaryOp\Concat
        ) {
            $this->dynamicFunctionCalls = true;
            $this->dynamicMethodScopes[FileReferences::ANY_CLASS] = true;
        }
    }

    private function methodCall(Expr $var, Node $name, bool $firstClassCallable): void
    {
        if (!$name instanceof Node\Identifier) {
            $this->dynamicMethodScopes[$this->scopeOf($var)] = true;
            return;
        }

        $firstClassCallable and $this->methods[$this->classOf($var) . '::' . $name->toLowerString()] = true;
    }

    private function staticCall(Expr\StaticCall $node): void
    {
        $class = $node->class instanceof Name ? $node->class : null;
        if (!$node->name instanceof Node\Identifier) {
            $this->dynamicMethodScopes[$class === null ? FileReferences::ANY_CLASS : $this->className($class)] = true;
            return;
        }

        if ($node->isFirstClassCallable()) {
            $this->methods[($class === null ? FileReferences::ANY_CLASS : $this->className($class)) . '::' . $node->name->toLowerString()] = true;
        }

        # `Closure::fromCallable('fn')` is covered by the string, `[..]` by the array.
    }

    /**
     * `new ReflectionClass(Foo::class)`, `new ReflectionMethod($this, 'm')`, `new ReflectionFunction('fn')`.
     */
    private function new(Expr\New_ $node): void
    {
        if (!$node->class instanceof Name || !\str_starts_with($node->class->toLowerString(), 'reflection')) {
            return;
        }

        $args = $node->getArgs();
        $first = $args[0]->value ?? null;
        if ($first === null) {
            return;
        }

        if ($first instanceof String_) {
            $this->reflectedClasses[\strtolower(\explode('::', \ltrim($first->value, '\\'))[0])] = true;
            $this->string($first->value);
            return;
        }

        $this->reflectedClasses[$this->classOf($first)] = true;
    }

    /**
     * Class of a callable target, {@see FileReferences::ANY_CLASS} when unknown.
     *
     * @return lowercase-string
     */
    private function classOf(Expr $target): string
    {
        return match (true) {
            $target instanceof Expr\Variable && $target->name === 'this' => $this->current() ?? FileReferences::ANY_CLASS,
            $target instanceof Expr\ClassConstFetch && $target->class instanceof Name
                && $target->name instanceof Node\Identifier && $target->name->toLowerString() === 'class' => $this->className($target->class),
            $target instanceof String_ => \strtolower(\ltrim($target->value, '\\')),
            $target instanceof Expr\New_ && $target->class instanceof Name => $this->className($target->class),
            default => FileReferences::ANY_CLASS,
        };
    }

    /**
     * Scope of a dynamic method call: the class when it calls its own methods, otherwise any class.
     *
     * @return lowercase-string
     */
    private function scopeOf(Expr $target): string
    {
        $class = $this->classOf($target);

        return $class === $this->current() ? $class : FileReferences::ANY_CLASS;
    }

    /**
     * @return lowercase-string
     */
    private function className(Name $name): string
    {
        if (\in_array($name->toLowerString(), ['self', 'static', 'parent'], true)) {
            # `parent` resolves to a class not known here.
            return $name->toLowerString() === 'parent' ? FileReferences::ANY_CLASS : ($this->current() ?? FileReferences::ANY_CLASS);
        }

        return \strtolower(\ltrim($name->toString(), '\\'));
    }

    /**
     * The enclosing named class; null outside classes and in an anonymous one.
     *
     * @return lowercase-string|null
     */
    private function current(): ?string
    {
        return $this->classes === [] ? null : $this->classes[\array_key_last($this->classes)];
    }
}
