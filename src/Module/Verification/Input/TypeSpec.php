<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

/**
 * A parameter or property type as far as input generation needs it: native types from reflection,
 * refined by phpdoc (`int<0, 10>`, `non-empty-string`, `list<int>`, `'a'|'b'`, shapes).
 *
 * @psalm-type Recipe = array<string, mixed>
 * @internal
 */
final readonly class TypeSpec
{
    public const INT = 'int';
    public const FLOAT = 'float';
    public const STRING = 'string';
    public const BOOL = 'bool';
    public const NULL = 'null';
    public const ARRAY = 'array';
    public const OBJECT = 'object';
    public const CLASS_ = 'class';
    public const MIXED = 'mixed';
    public const CALLABLE = 'callable';
    public const ITERABLE = 'iterable';
    public const LITERAL = 'literal';
    public const UNION = 'union';

    /**
     * @param self::* $kind
     * @param class-string|string|null $class For {@see self::CLASS_}.
     * @param list<self> $members For {@see self::UNION}.
     * @param list<Recipe> $values For {@see self::LITERAL}: the only allowed values.
     * @param array<array-key, array{self, bool}> $shape Array shape: key => [type, optional].
     */
    public function __construct(
        public string $kind,
        public ?string $class = null,
        public ?self $key = null,
        public ?self $value = null,
        public array $members = [],
        public array $values = [],
        public ?int $min = null,
        public ?int $max = null,
        public bool $nonEmpty = false,
        public bool $list = false,
        public bool $numeric = false,
        public array $shape = [],
    ) {}

    public static function of(string $kind): self
    {
        /** @var self::* $kind */
        return new self($kind);
    }

    public static function mixed(): self
    {
        return new self(self::MIXED);
    }

    public static function nullable(self $type): self
    {
        return $type->allowsNull() ? $type : self::union([$type, new self(self::NULL)]);
    }

    /**
     * @param list<self> $members
     */
    public static function union(array $members): self
    {
        $flat = [];
        foreach ($members as $member) {
            \array_push($flat, ...($member->kind === self::UNION ? $member->members : [$member]));
        }

        return \count($flat) === 1 ? $flat[0] : new self(self::UNION, members: $flat);
    }

    public function allowsNull(): bool
    {
        return match ($this->kind) {
            self::NULL, self::MIXED => true,
            self::UNION => \array_filter($this->members, static fn(self $m): bool => $m->allowsNull()) !== [],
            self::LITERAL => \in_array(['type' => 'null'], $this->values, true),
            default => false,
        };
    }

    /**
     * Scalar kinds this type accepts, for matching literals of the body.
     *
     * @return list<self::*>
     */
    public function scalarKinds(): array
    {
        return match ($this->kind) {
            self::INT, self::FLOAT, self::STRING, self::BOOL => [$this->kind],
            self::MIXED => [self::INT, self::FLOAT, self::STRING, self::BOOL],
            self::UNION => \array_values(\array_unique(\array_merge(...\array_map(static fn(self $m): array => $m->scalarKinds(), $this->members)))),
            default => [],
        };
    }
}
