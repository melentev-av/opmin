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
 * {@see Flag::Reflection}; and everything that can write a property bypassing its class (closures
 * that may be bound to it, reflection, a second constructor call), which a stable property must
 * not have.
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

    /** Methods that initialize a new object: only they may write a stable property. */
    private const array INITIALIZERS = ['__construct' => true, '__clone' => true, '__wakeup' => true, '__unserialize' => true];

    /** Methods through which a subclass may serve reads of a property it unset. */
    private const array MAGIC = ['__get' => true, '__set' => true, '__isset' => true, '__unset' => true];

    /** Methods that write a property through reflection (an existing object's). */
    private const array REFLECTION_WRITERS = [
        'setvalue' => true, 'setrawvalue' => true, 'setrawvaluewithoutlazyinitialization' => true,
        'skiplazyinitialization' => true, 'resetaslazyghost' => true, 'resetaslazyproxy' => true,
        # The constructor through reflection may be invoked on an existing object.
        'getconstructor' => true,
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

    /** @var list<array{bool, lowercase-string|null, bool}> Enclosing functions: [closure, method name, returns by reference]. */
    private array $functionLikes = [];

    /** @var array<non-empty-string, true> */
    private array $closureWrites = [];

    /** @var array<non-empty-string, true> */
    private array $outsideWrites = [];

    /** @var array<lowercase-string, true> */
    private array $magicClasses = [];

    private bool $reinitializes = false;
    private bool $reflectionWrites = false;
    private bool $walksObjects = false;
    private ?PropertyWrites $writes = null;

    /**
     * @param array<array-key, Node> $stmts Parsed, without name resolution.
     */
    public function collect(array $stmts): FileReferences
    {
        $this->functions = $this->methods = $this->dynamicMethodScopes = $this->reflectedClasses = [];
        $this->dynamicFunctionCalls = false;
        $this->classes = $this->functionLikes = $this->closureWrites = $this->outsideWrites = $this->magicClasses = [];
        $this->reinitializes = $this->reflectionWrites = $this->walksObjects = false;
        $this->writes ??= new PropertyWrites(self::byReference(...), static fn(Expr $e): bool => $e instanceof Expr\Array_);
        (new NodeTraverser(new NameResolver(), $this))->traverse($stmts);

        return new FileReferences(
            functions: self::sorted($this->functions),
            methods: self::sorted($this->methods),
            dynamicFunctionCalls: $this->dynamicFunctionCalls,
            dynamicMethodScopes: self::sorted($this->dynamicMethodScopes),
            reflectedClasses: self::sorted($this->reflectedClasses),
            closureWrites: self::sortedNames($this->closureWrites),
            reinitializes: $this->reinitializes,
            reflectionWrites: $this->reflectionWrites,
            walksObjects: $this->walksObjects,
            outsideWrites: self::sortedNames($this->outsideWrites),
            magicClasses: self::sorted($this->magicClasses),
        );
    }

    public function enterNode(Node $node): null
    {
        $node instanceof Node\FunctionLike and $this->functionLikes[] = [
            $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction,
            $node instanceof Stmt\ClassMethod ? $node->name->toLowerString() : null,
            $node->returnsByRef(),
        ];
        $this->effects($node);
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
        $node instanceof Node\FunctionLike and \array_pop($this->functionLikes);

        return null;
    }

    /**
     * @param array<non-empty-string, true> $set
     * @return list<non-empty-string>
     */
    private static function sortedNames(array $set): array
    {
        $list = [];
        foreach ($set as $name => $_) {
            $list[] = (string) $name;
        }

        \sort($list, \SORT_STRING);

        /** @var list<non-empty-string> */
        return $list;
    }

    /**
     * Whether an argument of a call is passed by reference, for a write inside a closure: known only
     * for internal functions (of the PHP running opmin).
     */
    private static function byReference(Expr\CallLike $call, int $position): ?bool
    {
        $name = $call instanceof Expr\FuncCall ? DynamicScopeDetector::globalFunction($call->name) : null;
        if ($name === null || !\function_exists($name)) {
            return null;
        }

        /** @var callable-string $name */
        $function = new \ReflectionFunction($name);
        if (!$function->isInternal()) {
            return null;
        }

        $parameters = $function->getParameters();
        $parameter = $parameters[$position] ?? null;
        if ($parameter === null && $parameters !== [] && $parameters[\count($parameters) - 1]->isVariadic()) {
            $parameter = $parameters[\count($parameters) - 1];
        }

        return $parameter?->isPassedByReference() ?? false;
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
     * What may write a property bypassing its class.
     */
    private function effects(Node $node): void
    {
        \assert($this->writes !== null);
        $this->walksObjects = $this->walksObjects || $this->writes->walksObject($node);
        $function = $this->functionLikes === [] ? null : $this->functionLikes[\array_key_last($this->functionLikes)];
        # A closure may be bound to any class: it writes the properties of that class.
        $initializer = $function !== null && !$function[0] && $function[1] !== null && isset(self::INITIALIZERS[$function[1]]);
        foreach ($this->writes->of($node, $function[2] ?? false) as $write) {
            $function !== null && $function[0] and $this->closureWrites[$write->name] = true;
            $initializer && $write->viaThis && !$write->reference or $this->outsideWrites[$write->name] = true;
        }

        $class = $this->current();
        $node instanceof Stmt\ClassMethod && $class !== null && isset(self::MAGIC[$node->name->toLowerString()])
            and $this->magicClasses[$class] = true;

        $name = $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall
            ? $node->name : null;
        $name = match (true) {
            $name instanceof Node\Identifier => $name->toLowerString(),
            $name instanceof String_ => \strtolower($name->value),
            default => null,
        };
        if ($name === null) {
            return;
        }

        isset(self::REFLECTION_WRITERS[$name]) and $this->reflectionWrites = true;
        # `parent::__construct()` of a constructor initializes the same new object.
        $initializing = $node instanceof Expr\StaticCall && $node->class instanceof Name && $node->class->toLowerString() === 'parent'
            && $function !== null && $function[1] === $name;
        isset(self::INITIALIZERS[$name]) && !$initializing and $this->reinitializes = true;
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
            isset(self::INITIALIZERS[\strtolower($m[2])]) and $this->reinitializes = true;
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
            isset(self::INITIALIZERS[\strtolower($method->value->value)]) and $this->reinitializes = true;
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

        $method = $args[1]->value ?? null;
        $method instanceof String_ && isset(self::INITIALIZERS[\strtolower($method->value)]) and $this->reinitializes = true;
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
