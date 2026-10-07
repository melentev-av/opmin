<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input\TypeParser;
use Opmin\Module\Verification\Input\TypeSpec;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TypeParser::class)]
#[Covers(TypeSpec::class)]
final class TypeParserTest
{
    /**
     * @return iterable<string, array{string, bool, string, ?string}> [name, builtin, kind, class]
     */
    public static function nativeNames(): iterable
    {
        foreach (['int' => 'int', 'float' => 'float', 'string' => 'string', 'bool' => 'bool', 'null' => 'null', 'void' => 'null', 'array' => 'array', 'iterable' => 'iterable', 'callable' => 'callable', 'Closure' => 'callable', 'object' => 'object', 'mixed' => 'mixed', 'true' => 'literal', 'false' => 'literal', 'INT' => 'int', 'never' => 'mixed'] as $name => $kind) {
            yield $name => [$name, true, $kind, null];
        }

        yield 'self' => ['self', false, 'class', 'App\Self'];
        yield 'static' => ['static', false, 'class', 'App\Self'];
        yield 'class with a leading backslash' => ['\App\Money', false, 'class', 'App\Money'];
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}> [phpdoc type, kind, properties to check]
     */
    public static function docTypes(): iterable
    {
        yield 'negative-int' => ['negative-int', 'int', ['max' => -1, 'min' => null]];
        yield 'non-negative-int' => ['non-negative-int', 'int', ['min' => 0, 'max' => null]];
        yield 'non-positive-int' => ['non-positive-int', 'int', ['max' => 0]];
        yield 'non-falsy-string' => ['non-falsy-string', 'string', ['nonEmpty' => true]];
        yield 'truthy-string' => ['truthy-string', 'string', ['nonEmpty' => true]];
        foreach (['lowercase-string', 'class-string', 'literal-string', 'callable-string'] as $name) {
            yield $name => [$name, 'string', ['nonEmpty' => false]];
        }

        yield 'list' => ['list', 'array', ['list' => true, 'nonEmpty' => false]];
        yield 'non-empty-list' => ['non-empty-list', 'array', ['list' => true, 'nonEmpty' => true]];
        yield 'non-empty-array' => ['non-empty-array', 'array', ['list' => false, 'nonEmpty' => true]];
        yield 'non-empty-list<int>' => ['non-empty-list<int>', 'array', ['list' => true, 'nonEmpty' => true]];
        yield 'non-empty-array<string, int>' => ['non-empty-array<string, int>', 'array', ['nonEmpty' => true]];
        yield 'iterable<int>' => ['iterable<int>', 'array', ['list' => false]];
        yield 'array<Unknown-thing>' => ['array<foo-bar>', 'array', ['value' => null]];
        yield 'double' => ['double', 'float', []];
        yield 'number' => ['number', 'float', []];
        yield 'integer' => ['integer', 'int', []];
        yield 'boolean' => ['boolean', 'bool', []];
        yield 'scalar' => ['scalar', 'union', []];
        yield 'numeric' => ['numeric', 'union', []];
        yield 'array-key' => ['array-key', 'union', []];
        yield 'int[]' => ['int[]', 'array', []];
        yield '?int' => ['?int', 'union', []];
        yield 'callable(int): void' => ['callable(int): void', 'callable', []];
        yield '$this' => ['$this', 'class', ['class' => 'App\Self']];
        yield 'A&B' => ['Countable&Stringable', 'class', ['class' => 'App\Domain\Countable']];
        yield 'Collection<Item>' => ['Collection<Item>', 'class', ['class' => 'App\Domain\Collection']];
        yield 'STRING (case)' => ['STRING', 'string', []];
        yield '5' => ['5', 'literal', ['values' => [['type' => 'int', 'value' => 5]]]];
        yield '1.5' => ['1.5', 'literal', ['values' => [['type' => 'float', 'value' => '1.5']]]];
        yield 'true|false' => ['true|false', 'literal', ['values' => [['type' => 'bool', 'value' => true], ['type' => 'bool', 'value' => false]]]];
        yield 'true|null' => ['true|null', 'union', []];
        yield 'false' => ['false', 'literal', ['values' => [['type' => 'bool', 'value' => false]]]];
        yield "'a'|int" => ["'a'|int", 'union', []];
        yield 'int<min, 5>' => ['int<min, 5>', 'int', ['min' => null, 'max' => 5]];
        yield 'array{int, string}' => ['array{int, string}', 'array', ['shape' => [0, 1]]];
        yield 'array{5: int}' => ['array{5: int}', 'array', ['shape' => [5]]];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedDocTypes(): iterable
    {
        foreach (['resource', 'never', 'void', 'never-return', 'noreturn', 'non-existent-type', 'array{foo: resource}|never', '?resource', 'resource[]', 'resource&Countable'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('nativeNames')]
    public function readsNativeNames(string $name, bool $builtin, string $kind, ?string $class): void
    {
        $type = self::parser()->native(['name' => $name, 'builtin' => $builtin, 'nullable' => false]);

        Assert::same([$type->kind, $type->class], [$kind, $class]);
    }

    /**
     * @param array<string, mixed> $properties
     */
    #[DataProvider('docTypes')]
    public function readsPhpdocTypes(string $type, string $kind, array $properties): void
    {
        $parsed = self::parser()->params("/** @param {$type} \$x */")['x'] ?? null;

        Assert::notNull($parsed);
        Assert::same($parsed->kind, $kind);
        foreach ($properties as $name => $value) {
            $actual = $name === 'shape' ? \array_keys($parsed->shape) : ($name === 'value' ? $parsed->value : $parsed->{$name});
            Assert::same($actual, $value, $name);
        }
    }

    #[DataProvider('unsupportedDocTypes')]
    public function unsupportedPhpdocTypesAreIgnored(string $type): void
    {
        $parsed = self::parser()->params("/** @param {$type} \$x */")['x'] ?? null;

        \str_starts_with($type, 'array{') ? Assert::null($parsed) : Assert::null($parsed);
    }

    public function readsVarTagsAndUnionsOfNativeTypes(): void
    {
        $parser = self::parser();
        $var = $parser->var('/** @var positive-int */');
        $psalm = $parser->var("/** @var int\n * @psalm-var non-empty-string */");
        $nullableUnion = $parser->native(['union' => [['name' => 'int', 'builtin' => true, 'nullable' => false], ['name' => 'null', 'builtin' => true, 'nullable' => true]]]);
        $intersection = $parser->native(['intersection' => [['name' => 'Countable', 'builtin' => false, 'nullable' => false], ['name' => 'Stringable', 'builtin' => false, 'nullable' => false]]]);

        Assert::same($var?->min, 1);
        Assert::same($psalm?->kind, 'string');
        Assert::true($nullableUnion->allowsNull());
        Assert::same($intersection->class, 'Countable');
        Assert::same($parser->native(['intersection' => []])->kind, 'mixed');
        Assert::same($parser->native(['name' => 'int', 'builtin' => true])->kind, 'int');
        Assert::same($parser->native([])->kind, 'mixed');
        Assert::null((new TypeParser(static fn(string $n): string => $n))->params('/** @param $this $x */')['x'] ?? null);
        Assert::same((new TypeParser(static fn(string $n): string => $n))->native(['name' => 'self', 'builtin' => false, 'nullable' => false])->kind, 'object');
    }

    public function refinementKeepsSpecificNativeTypes(): void
    {
        $doc = new TypeSpec(TypeSpec::INT, min: 1);
        $vague = [TypeSpec::ARRAY, TypeSpec::ITERABLE, TypeSpec::INT, TypeSpec::STRING, TypeSpec::FLOAT, TypeSpec::OBJECT];
        foreach ($vague as $kind) {
            Assert::same(TypeParser::refine(TypeSpec::of($kind), $doc)->kind, 'int', $kind);
        }

        # mixed and null admit null: the refined type stays nullable.
        Assert::same(TypeParser::refine(TypeSpec::of(TypeSpec::MIXED), $doc)->kind, 'union');
        Assert::same(TypeParser::refine(TypeSpec::of(TypeSpec::NULL), $doc)->kind, 'union');
        Assert::same(TypeParser::refine(TypeSpec::of(TypeSpec::BOOL), $doc)->kind, 'bool');
        Assert::same(TypeParser::refine(TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::BOOL)]), $doc)->kind, 'union');
        Assert::same(TypeParser::refine(TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::STRING)]), $doc)->kind, 'int');
        Assert::same(TypeParser::refine(TypeSpec::of(TypeSpec::INT), null)->kind, 'int');
    }

    public function readsNativeTypes(): void
    {
        $parser = self::parser();

        Assert::same($parser->native(null)->kind, TypeSpec::MIXED);
        Assert::same($parser->native(['name' => 'int', 'builtin' => true, 'nullable' => false])->kind, TypeSpec::INT);
        Assert::true($parser->native(['name' => 'string', 'builtin' => true, 'nullable' => true])->allowsNull());
        Assert::same($parser->native(['name' => 'App\Money', 'builtin' => false, 'nullable' => false])->class, 'App\Money');
        Assert::same($parser->native(['name' => 'self', 'builtin' => false, 'nullable' => false])->class, 'App\Self');
        $union = $parser->native(['union' => [['name' => 'int', 'builtin' => true, 'nullable' => false], ['name' => 'string', 'builtin' => true, 'nullable' => false]]]);
        Assert::same($union->scalarKinds(), [TypeSpec::INT, TypeSpec::STRING]);
    }

    public function readsPhpdocParams(): void
    {
        $doc = <<<'DOC'
            /**
             * @param int<0, 10> $range
             * @param non-empty-string $name
             * @param list<int> $ids
             * @param array<string, Money> $byName
             * @param 'a'|'b' $mode
             * @param array{id: int, tag?: string} $row
             * @param positive-int $count
             * @psalm-param numeric-string $amount
             * @param string $amount
             */
            DOC;

        $params = self::parser()->params($doc);

        Assert::same([$params['range']->min, $params['range']->max], [0, 10]);
        Assert::true($params['name']->nonEmpty);
        Assert::same([$params['ids']->list, $params['ids']->value?->kind], [true, TypeSpec::INT]);
        Assert::same([$params['byName']->key?->kind, $params['byName']->value?->class], [TypeSpec::STRING, 'App\Domain\Money']);
        Assert::same($params['mode']->values, [['type' => 'string', 'value' => 'a'], ['type' => 'string', 'value' => 'b']]);
        Assert::same(\array_keys($params['row']->shape), ['id', 'tag']);
        Assert::same($params['row']->shape['tag'][1], true);
        Assert::same($params['count']->min, 1);
        Assert::true($params['amount']->numeric);
    }

    public function phpdocRefinesOnlyVagueNativeTypes(): void
    {
        $doc = new TypeSpec(TypeSpec::INT, min: 1);

        Assert::same(TypeParser::refine(TypeSpec::of(TypeSpec::INT), $doc)->min, 1);
        Assert::same(TypeParser::refine(new TypeSpec(TypeSpec::CLASS_, 'App\A'), $doc)->kind, TypeSpec::CLASS_);
        Assert::true(TypeParser::refine(TypeSpec::nullable(TypeSpec::of(TypeSpec::INT)), $doc)->allowsNull());
    }

    public function brokenDocblockGivesNothing(): void
    {
        Assert::same(self::parser()->params('/** @param int<0, $x */'), []);
        Assert::null(self::parser()->var(null));
    }

    private static function parser(): TypeParser
    {
        return new TypeParser(static fn(string $name): string => 'App\Domain\\' . $name, 'App\Self');
    }
}
