<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input\Recipes;
use Opmin\Module\Verification\Input\TypeSpec;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TypeSpec::class)]
final class TypeSpecTest
{
    public function unionsAreFlatAndOneMemberIsItself(): void
    {
        $int = TypeSpec::of(TypeSpec::INT);
        $union = TypeSpec::union([$int, TypeSpec::union([TypeSpec::of(TypeSpec::STRING), TypeSpec::of(TypeSpec::INT)])]);

        Assert::same(TypeSpec::union([$int]), $int);
        Assert::same(TypeSpec::union([TypeSpec::union([$int])]), $int);
        Assert::same(\array_map(static fn(TypeSpec $m): string => $m->kind, $union->members), [TypeSpec::INT, TypeSpec::STRING, TypeSpec::INT]);
        Assert::same($union->scalarKinds(), [TypeSpec::INT, TypeSpec::STRING]);
    }

    public function nullIsAllowedByNullMixedUnionsAndLiterals(): void
    {
        Assert::true(TypeSpec::of(TypeSpec::NULL)->allowsNull());
        Assert::true(TypeSpec::mixed()->allowsNull());
        Assert::false(TypeSpec::of(TypeSpec::INT)->allowsNull());
        Assert::true(TypeSpec::nullable(TypeSpec::of(TypeSpec::INT))->allowsNull());
        Assert::false(TypeSpec::union([TypeSpec::of(TypeSpec::INT), TypeSpec::of(TypeSpec::STRING)])->allowsNull());
        Assert::true((new TypeSpec(TypeSpec::LITERAL, values: [Recipes::int(1), Recipes::null()]))->allowsNull());
        Assert::false((new TypeSpec(TypeSpec::LITERAL, values: [Recipes::int(1)]))->allowsNull());

        $mixed = TypeSpec::mixed();
        Assert::same(TypeSpec::nullable($mixed), $mixed);
    }

    public function scalarKindsOfEveryKind(): void
    {
        foreach ([TypeSpec::INT, TypeSpec::FLOAT, TypeSpec::STRING, TypeSpec::BOOL] as $kind) {
            Assert::same(TypeSpec::of($kind)->scalarKinds(), [$kind]);
        }

        Assert::same(TypeSpec::mixed()->scalarKinds(), [TypeSpec::INT, TypeSpec::FLOAT, TypeSpec::STRING, TypeSpec::BOOL]);
        Assert::same(TypeSpec::of(TypeSpec::NULL)->scalarKinds(), []);
        Assert::same(TypeSpec::nullable(TypeSpec::of(TypeSpec::FLOAT))->scalarKinds(), [TypeSpec::FLOAT]);
    }
}
