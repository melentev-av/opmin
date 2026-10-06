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
     */
    public function __construct(
        public array $functions = [],
        public array $methods = [],
        public bool $dynamicFunctionCalls = false,
        public array $dynamicMethodScopes = [],
        public array $reflectedClasses = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{functions: list<lowercase-string>, methods: list<lowercase-string>, dynamic_function_calls: bool, dynamic_method_scopes: list<lowercase-string>, reflected_classes: list<lowercase-string>} $data */
        return new self(
            $data['functions'],
            $data['methods'],
            $data['dynamic_function_calls'],
            $data['dynamic_method_scopes'],
            $data['reflected_classes'],
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
        ];
    }
}
