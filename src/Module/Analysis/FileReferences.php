<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

/**
 * How one file refers to functions and methods without a static call site: by name in a string,
 * as an array callable, through a dynamic call or reflection. Collected by {@see ReferenceCollector}.
 *
 * Names are lower-case, classes and functions fully qualified without the leading `\`.
 *
 * @internal
 */
final readonly class FileReferences
{
    /** Class part of a method reference whose class is unknown. */
    public const ANY_CLASS = '*';

    /**
     * @param list<lowercase-string> $functions Functions named in strings or turned into callables.
     * @param list<lowercase-string> $methods `class::method` (class may be {@see self::ANY_CLASS}).
     * @param bool $dynamicFunctionCalls A call of an unknown function (`$fn()`, `call_user_func($fn)`).
     * @param list<lowercase-string> $dynamicMethodScopes Classes with a call of an unknown method
     *        (`$obj->$name()`); {@see self::ANY_CLASS} when the call is outside a class or on another object.
     * @param list<lowercase-string> $reflectedClasses Classes inspected through `Reflection*`.
     * @param list<non-empty-string> $closureWrites Properties a closure writes or references (any
     *        object's): bound to a class (`Closure::bind()`, `->call()`) it writes even a private one;
     *        {@see PropertyWrites::ANY} for any property.
     * @param bool $reinitializes `__construct()`, `__clone()`, `__wakeup()` or `__unserialize()` is
     *        called explicitly on an object that exists (not `parent::__construct()` in a constructor).
     * @param bool $reflectionWrites A method that writes a property through reflection is called
     *        (`setValue()`, `resetAsLazyGhost()`…): with a class in $reflectedClasses its properties change.
     * @param bool $walksObjects `array_walk()` or `new ArrayObject()` of what may be an object: it
     *        writes any property, private ones too, from any scope.
     * @param list<non-empty-string> $outsideWrites Properties written or referenced (any object's)
     *        other than as `$this->p` in an initializer — what a closed world must rule out for a
     *        `protected`/`public` property to be stable; {@see PropertyWrites::ANY} for any property.
     * @param list<lowercase-string> $magicClasses Classes declaring `__get()`, `__set()`, `__isset()`
     *        or `__unset()`: a subclass of a property's class among them may unset it and serve reads.
     */
    public function __construct(
        public array $functions = [],
        public array $methods = [],
        public bool $dynamicFunctionCalls = false,
        public array $dynamicMethodScopes = [],
        public array $reflectedClasses = [],
        public array $closureWrites = [],
        public bool $reinitializes = false,
        public bool $reflectionWrites = false,
        public bool $walksObjects = false,
        public array $outsideWrites = [],
        public array $magicClasses = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{functions: list<lowercase-string>, methods: list<lowercase-string>, dynamic_function_calls: bool, dynamic_method_scopes: list<lowercase-string>, reflected_classes: list<lowercase-string>, closure_writes: list<non-empty-string>, reinitializes: bool, reflection_writes: bool, walks_objects: bool, outside_writes?: list<non-empty-string>, magic_classes?: list<lowercase-string>} $data */
        return new self(
            $data['functions'],
            $data['methods'],
            $data['dynamic_function_calls'],
            $data['dynamic_method_scopes'],
            $data['reflected_classes'],
            $data['closure_writes'],
            $data['reinitializes'],
            $data['reflection_writes'],
            $data['walks_objects'],
            $data['outside_writes'] ?? [],
            $data['magic_classes'] ?? [],
        );
    }

    /**
     * The facts of property writes (and the reflected classes) of both sets, the rest left out.
     */
    public function effectsWith(self $other): self
    {
        $classes = \array_values(\array_unique([...$this->reflectedClasses, ...$other->reflectedClasses]));
        $writes = \array_values(\array_unique([...$this->closureWrites, ...$other->closureWrites]));
        $outside = \array_values(\array_unique([...$this->outsideWrites, ...$other->outsideWrites]));
        $magic = \array_values(\array_unique([...$this->magicClasses, ...$other->magicClasses]));
        \sort($classes, \SORT_STRING);
        \sort($writes, \SORT_STRING);
        \sort($outside, \SORT_STRING);
        \sort($magic, \SORT_STRING);

        return new self(
            reflectedClasses: $classes,
            closureWrites: $writes,
            reinitializes: $this->reinitializes || $other->reinitializes,
            reflectionWrites: $this->reflectionWrites || $other->reflectionWrites,
            walksObjects: $this->walksObjects || $other->walksObjects,
            outsideWrites: $outside,
            magicClasses: $magic,
        );
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function toArray(): array
    {
        return [
            'functions' => $this->functions,
            'methods' => $this->methods,
            'dynamic_function_calls' => $this->dynamicFunctionCalls,
            'dynamic_method_scopes' => $this->dynamicMethodScopes,
            'reflected_classes' => $this->reflectedClasses,
            'closure_writes' => $this->closureWrites,
            'reinitializes' => $this->reinitializes,
            'reflection_writes' => $this->reflectionWrites,
            'walks_objects' => $this->walksObjects,
            'outside_writes' => $this->outsideWrites,
            'magic_classes' => $this->magicClasses,
        ];
    }
}
