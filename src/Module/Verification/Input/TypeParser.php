<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser as DocTypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Builds {@see TypeSpec}s from the harness's reflection types and from phpdoc.
 *
 * phpdoc refines a native type only where the native type says little (`array`, `int`, `string`,
 * `mixed`, none); an unsupported phpdoc type falls back to the native one. Input generation may
 * stray outside the declared types anyway — a wrong guess costs coverage, never a false verdict.
 *
 * @internal
 */
final class TypeParser
{
    private readonly Lexer $lexer;
    private readonly PhpDocParser $docParser;

    /**
     * @param \Closure(string): string $resolve Resolves a class name of the phpdoc (namespace, `use`).
     * @param string|null $self The class `self`/`static` refer to.
     */
    public function __construct(
        private readonly \Closure $resolve,
        private readonly ?string $self = null,
    ) {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExpr = new ConstExprParser($config);
        $this->docParser = new PhpDocParser($config, new DocTypeParser($config, $constExpr), $constExpr);
    }

    /**
     * The phpdoc type where it refines the native one.
     */
    public static function refine(TypeSpec $native, ?TypeSpec $doc): TypeSpec
    {
        if ($doc === null) {
            return $native;
        }

        $vague = [TypeSpec::MIXED, TypeSpec::ARRAY, TypeSpec::ITERABLE, TypeSpec::INT, TypeSpec::STRING, TypeSpec::FLOAT, TypeSpec::OBJECT];
        $kinds = $native->kind === TypeSpec::UNION ? \array_map(static fn(TypeSpec $m): string => $m->kind, $native->members) : [$native->kind];
        foreach ($kinds as $kind) {
            if (!\in_array($kind, [...$vague, TypeSpec::NULL], true)) {
                return $native;
            }
        }

        return $native->allowsNull() && !$doc->allowsNull() ? TypeSpec::nullable($doc) : $doc;
    }

    /**
     * @param array<array-key, mixed>|null $native Type description of the harness (`describe`).
     */
    public function native(?array $native): TypeSpec
    {
        if ($native === null) {
            return TypeSpec::mixed();
        }

        if (isset($native['union']) && \is_array($native['union'])) {
            /** @var list<array<array-key, mixed>|null> $members */
            $members = $native['union'];

            return TypeSpec::union(\array_map($this->native(...), $members));
        }

        if (isset($native['intersection']) && \is_array($native['intersection'])) {
            # A mock implements one type: the first one of the intersection.
            /** @var list<array<array-key, mixed>|null> $members */
            $members = $native['intersection'];

            return $members === [] ? TypeSpec::mixed() : $this->native($members[0]);
        }

        $name = (string) ($native['name'] ?? 'mixed');
        $type = $this->named($name, ($native['builtin'] ?? false) === true);

        return ($native['nullable'] ?? false) === true ? TypeSpec::nullable($type) : $type;
    }

    /**
     * Types of `@param` tags by parameter name (without `$`).
     *
     * @return array<string, TypeSpec>
     */
    public function params(?string $doc): array
    {
        $result = [];
        foreach ($this->tags($doc, ['@param', '@psalm-param', '@phpstan-param']) as $tag) {
            if ($tag instanceof ParamTagValueNode) {
                $type = $this->doc($tag->type);
                $type === null or $result[\ltrim($tag->parameterName, '$')] = $type;
            }
        }

        return $result;
    }

    /**
     * Type of the `@var` tag of a property.
     */
    public function var(?string $doc): ?TypeSpec
    {
        $type = null;
        # The last one wins: tags() puts the Psalm/PHPStan-prefixed ones last.
        foreach ($this->tags($doc, ['@var', '@psalm-var', '@phpstan-var']) as $tag) {
            $tag instanceof VarTagValueNode and $type = $this->doc($tag->type) ?? $type;
        }

        return $type;
    }

    private static function bound(Type\TypeNode $node): ?int
    {
        if ($node instanceof Type\ConstTypeNode && $node->constExpr instanceof ConstExprIntegerNode) {
            return (int) $node->constExpr->value;
        }

        return null;
    }

    private function named(string $name, bool $builtin): TypeSpec
    {
        $lower = \strtolower(\ltrim($name, '\\'));

        return match ($lower) {
            'int' => TypeSpec::of(TypeSpec::INT),
            'float' => TypeSpec::of(TypeSpec::FLOAT),
            'string' => TypeSpec::of(TypeSpec::STRING),
            'bool' => TypeSpec::of(TypeSpec::BOOL),
            'true' => new TypeSpec(TypeSpec::LITERAL, values: [['type' => 'bool', 'value' => true]]),
            'false' => new TypeSpec(TypeSpec::LITERAL, values: [['type' => 'bool', 'value' => false]]),
            'null', 'void' => TypeSpec::of(TypeSpec::NULL),
            'array' => TypeSpec::of(TypeSpec::ARRAY),
            'iterable' => TypeSpec::of(TypeSpec::ITERABLE),
            'callable', 'closure' => TypeSpec::of(TypeSpec::CALLABLE),
            'object' => TypeSpec::of(TypeSpec::OBJECT),
            'mixed' => TypeSpec::mixed(),
            'self', 'static', '$this' => $this->self === null ? TypeSpec::of(TypeSpec::OBJECT) : new TypeSpec(TypeSpec::CLASS_, $this->self),
            default => $builtin ? TypeSpec::mixed() : new TypeSpec(TypeSpec::CLASS_, \ltrim($name, '\\')),
        };
    }

    /**
     * @param list<string> $names
     * @return list<object>
     */
    private function tags(?string $doc, array $names): array
    {
        if ($doc === null || $doc === '') {
            return [];
        }

        try {
            $node = $this->docParser->parse(new TokenIterator($this->lexer->tokenize($doc)));
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        # Psalm/PHPStan-prefixed tags win over plain ones: read them last.
        foreach (\array_reverse($names) as $name) {
            foreach ($node->getTagsByName($name) as $tag) {
                $result[] = $tag->value;
            }
        }

        return \array_reverse($result);
    }

    private function doc(Type\TypeNode $node): ?TypeSpec
    {
        return match (true) {
            $node instanceof Type\NullableTypeNode => ($inner = $this->doc($node->type)) === null ? null : TypeSpec::nullable($inner),
            $node instanceof Type\UnionTypeNode => $this->docUnion($node->types),
            $node instanceof Type\IntersectionTypeNode => $node->types === [] ? null : $this->doc($node->types[0]),
            $node instanceof Type\ArrayTypeNode => ($inner = $this->doc($node->type)) === null ? null : new TypeSpec(TypeSpec::ARRAY, value: $inner),
            $node instanceof Type\GenericTypeNode => $this->generic($node),
            $node instanceof Type\ArrayShapeNode => $this->shape($node),
            $node instanceof Type\ConstTypeNode => $this->constant($node),
            $node instanceof Type\IdentifierTypeNode => $this->identifier($node->name),
            $node instanceof Type\ThisTypeNode => $this->self === null ? null : new TypeSpec(TypeSpec::CLASS_, $this->self),
            $node instanceof Type\CallableTypeNode => TypeSpec::of(TypeSpec::CALLABLE),
            default => null,
        };
    }

    /**
     * @param array<Type\TypeNode> $nodes
     */
    private function docUnion(array $nodes): ?TypeSpec
    {
        $members = [];
        foreach ($nodes as $node) {
            $member = $this->doc($node);
            if ($member === null) {
                return null;
            }

            $members[] = $member;
        }

        # 'a'|'b' — one literal type.
        $literals = \array_filter($members, static fn(TypeSpec $m): bool => $m->kind === TypeSpec::LITERAL);
        if (\count($literals) === \count($members)) {
            return new TypeSpec(TypeSpec::LITERAL, values: \array_merge(...\array_map(static fn(TypeSpec $m): array => $m->values, $members)));
        }

        return TypeSpec::union($members);
    }

    private function identifier(string $name): ?TypeSpec
    {
        return match (\strtolower($name)) {
            'positive-int' => new TypeSpec(TypeSpec::INT, min: 1),
            'negative-int' => new TypeSpec(TypeSpec::INT, max: -1),
            'non-negative-int' => new TypeSpec(TypeSpec::INT, min: 0),
            'non-positive-int' => new TypeSpec(TypeSpec::INT, max: 0),
            'non-empty-string', 'non-falsy-string', 'truthy-string' => new TypeSpec(TypeSpec::STRING, nonEmpty: true),
            'numeric-string' => new TypeSpec(TypeSpec::STRING, numeric: true),
            'lowercase-string', 'class-string', 'literal-string', 'callable-string' => TypeSpec::of(TypeSpec::STRING),
            'list' => new TypeSpec(TypeSpec::ARRAY, list: true),
            'non-empty-list' => new TypeSpec(TypeSpec::ARRAY, nonEmpty: true, list: true),
            'non-empty-array' => new TypeSpec(TypeSpec::ARRAY, nonEmpty: true),
            'scalar' => TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::FLOAT), TypeSpec::of(TypeSpec::STRING), TypeSpec::of(TypeSpec::BOOL)]),
            'numeric' => TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::FLOAT), new TypeSpec(TypeSpec::STRING, numeric: true)]),
            'array-key' => TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::STRING)]),
            'double', 'number' => TypeSpec::of(TypeSpec::FLOAT),
            'integer' => TypeSpec::of(TypeSpec::INT),
            'boolean' => TypeSpec::of(TypeSpec::BOOL),
            'resource', 'never', 'void', 'never-return', 'noreturn' => null,
            default => \str_contains($name, '-')
                ? null
                : (\in_array(\strtolower($name), ['int', 'float', 'string', 'bool', 'null', 'array', 'iterable', 'callable', 'object', 'mixed', 'true', 'false', 'self', 'static', 'closure'], true)
                    ? $this->named($name, true)
                    : new TypeSpec(TypeSpec::CLASS_, ($this->resolve)($name))),
        };
    }

    private function generic(Type\GenericTypeNode $node): ?TypeSpec
    {
        $name = \strtolower($node->type->name);
        $args = [];
        foreach ($node->genericTypes as $generic) {
            $args[] = $generic;
        }

        if ($name === 'int' && \count($args) === 2) {
            return new TypeSpec(TypeSpec::INT, min: self::bound($args[0]), max: self::bound($args[1]));
        }

        $list = \in_array($name, ['list', 'non-empty-list'], true);
        $nonEmpty = \str_starts_with($name, 'non-empty-');
        if ($list || \in_array($name, ['array', 'non-empty-array', 'iterable'], true)) {
            $last = $args === [] ? new Type\IdentifierTypeNode('mixed') : $args[\array_key_last($args)];
            $value = $this->doc($last);
            $key = !$list && \count($args) === 2 ? $this->doc($args[0]) : null;
            if ($value === null) {
                return new TypeSpec(TypeSpec::ARRAY, list: $list, nonEmpty: $nonEmpty);
            }

            return new TypeSpec(TypeSpec::ARRAY, key: $key, value: $value, nonEmpty: $nonEmpty, list: $list);
        }

        # A generic class (Collection<Item>): the class.
        return $this->identifier($node->type->name);
    }

    private function shape(Type\ArrayShapeNode $node): TypeSpec
    {
        $shape = [];
        $i = 0;
        foreach ($node->items as $item) {
            $type = $this->doc($item->valueType) ?? TypeSpec::mixed();
            $key = match (true) {
                $item->keyName instanceof ConstExprIntegerNode => (int) $item->keyName->value,
                $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                $item->keyName instanceof Type\IdentifierTypeNode => $item->keyName->name,
                default => $i++,
            };
            $shape[$key] = [$type, $item->optional];
        }

        return new TypeSpec(TypeSpec::ARRAY, shape: $shape);
    }

    private function constant(Type\ConstTypeNode $node): ?TypeSpec
    {
        $expr = $node->constExpr;
        $value = match (true) {
            $expr instanceof ConstExprIntegerNode => ['type' => 'int', 'value' => (int) $expr->value],
            $expr instanceof ConstExprFloatNode => ['type' => 'float', 'value' => (string) (float) $expr->value],
            $expr instanceof ConstExprStringNode => ['type' => 'string', 'value' => $expr->value],
            $expr instanceof ConstExprTrueNode => ['type' => 'bool', 'value' => true],
            $expr instanceof ConstExprFalseNode => ['type' => 'bool', 'value' => false],
            $expr instanceof ConstExprNullNode => ['type' => 'null'],
            default => null,
        };

        return $value === null ? null : new TypeSpec(TypeSpec::LITERAL, values: [$value]);
    }
}
