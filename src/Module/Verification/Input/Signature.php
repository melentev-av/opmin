<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

/**
 * What an input of the function consists of: parameters, `use` variables, the class of `$this`.
 *
 * @internal
 */
final readonly class Signature
{
    /**
     * @param list<Parameter> $params
     * @param list<Parameter> $uses
     * @param string|null $receiver Class of `$this`; null for functions, static methods, static closures.
     */
    public function __construct(
        public array $params,
        public array $uses = [],
        public ?string $receiver = null,
    ) {}

    /**
     * @param array<array-key, mixed> $function The `describe` result of the harness.
     * @param string|null $receiver Class of `$this` when the function needs one.
     */
    public static function fromDescribe(array $function, TypeParser $parser, ?string $receiver): self
    {
        $docs = $parser->params(\is_string($function['doc'] ?? null) ? $function['doc'] : null);

        return new self(
            self::params($function['params'] ?? [], $parser, $docs),
            self::params($function['uses'] ?? [], $parser, []),
            $receiver,
        );
    }

    /**
     * Number of arguments a call needs.
     *
     * @return non-negative-int
     */
    public function required(): int
    {
        $required = 0;
        foreach ($this->params as $i => $param) {
            $param->optional || $param->variadic or $required = $i + 1;
        }

        return $required;
    }

    /**
     * @param array<string, TypeSpec> $docs
     * @return list<Parameter>
     */
    private static function params(mixed $params, TypeParser $parser, array $docs): array
    {
        $result = [];
        /** @var array<string, mixed> $param */
        foreach (\is_array($params) ? $params : [] as $param) {
            $name = (string) ($param['name'] ?? '');
            $native = \is_array($param['type'] ?? null) ? $param['type'] : null;
            $result[] = new Parameter(
                name: $name,
                type: TypeParser::refine($parser->native($native), $docs[$name] ?? null),
                optional: ($param['optional'] ?? false) === true,
                variadic: ($param['variadic'] ?? false) === true,
                byRef: ($param['by_ref'] ?? false) === true,
                typed: $native !== null,
            );
        }

        return $result;
    }
}
