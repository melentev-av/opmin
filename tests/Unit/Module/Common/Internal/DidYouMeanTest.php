<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Common\Internal;

use Opmin\Module\Common\Internal\DidYouMean;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DidYouMean::class)]
final class DidYouMeanTest
{
    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function names(): iterable
    {
        $rules = ['fqn', 'property_fetch', 'array_dim_fetch', 'count', 'llm'];

        yield 'one letter wrong' => ['fqm', $rules, ['fqn']];
        yield 'case does not matter' => ['FQM', $rules, ['fqn']];
        yield 'a missing letter' => ['property_fech', $rules, ['property_fetch']];
        yield 'too far' => ['foo', $rules, []];
        yield 'a third of the length at most' => ['cnt', $rules, []];
        yield 'only the nearest distance' => ['abcdef', ['abcdxy', 'abcdeg', 'abcdez'], ['abcdeg', 'abcdez']];
        yield 'at most three' => ['ab', ['a', 'b', 'abc', 'abd', 'x'], ['a', 'b', 'abc']];
        yield 'nothing to choose from' => ['fqn', [], []];
    }

    #[DataProvider('names')]
    public function closest(string $name, array $candidates, array $expected): void
    {
        Assert::same(DidYouMean::closest($name, $candidates), $expected);
    }

    public function phrase(): void
    {
        Assert::same(DidYouMean::phrase([]), '');
        Assert::same(DidYouMean::phrase(['a']), ' Did you mean `a`?');
        Assert::same(DidYouMean::phrase(['a', 'b', 'c']), ' Did you mean `a`, `b` or `c`?');
    }
}
