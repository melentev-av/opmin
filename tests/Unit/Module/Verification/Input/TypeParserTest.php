<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input\TypeParser;
use Opmin\Module\Verification\Input\TypeSpec;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TypeParser::class)]
#[Covers(TypeSpec::class)]
final class TypeParserTest
{
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
