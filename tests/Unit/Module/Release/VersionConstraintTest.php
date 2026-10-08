<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Release;

use Opmin\Module\Release\VersionConstraint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(VersionConstraint::class)]
final class VersionConstraintTest
{
    public static function cases(): iterable
    {
        yield 'exact' => ['1.2.3', ['1.2.3', 'v1.2.3'], ['1.2.4', '1.2.2']];
        yield 'minor line' => ['1.2', ['1.2.0', '1.2.9'], ['1.3.0', '1.1.9']];
        yield 'minor wildcard' => ['1.2.*', ['1.2.0', '1.2.9'], ['1.3.0']];
        yield 'major line' => ['1', ['1.0.0', '1.9.9'], ['2.0.0', '0.9.0']];
        yield 'caret' => ['^1.2', ['1.2.0', '1.9.0'], ['2.0.0', '1.1.9']];
        yield 'caret zero major' => ['^0.3', ['0.3.0', '0.3.7'], ['0.4.0', '0.2.9']];
        yield 'caret zero minor' => ['^0.0.3', ['0.0.3'], ['0.0.4', '0.1.0']];
        yield 'tilde minor' => ['~1.2', ['1.2.0', '1.9.0'], ['2.0.0']];
        yield 'tilde patch' => ['~1.2.3', ['1.2.3', '1.2.9'], ['1.3.0', '1.2.2']];
        yield 'range' => ['>=1.2 <2', ['1.2.0', '1.99.0'], ['2.0.0', '1.1.0']];
        yield 'range with a comma' => ['>=1.2,<1.4', ['1.3.5'], ['1.4.0']];
        yield 'alternatives' => ['^0.2 || ^1.0', ['0.2.5', '1.3.0'], ['0.3.0', '2.0.0']];
        yield 'single pipe' => ['0.2.* | 1.0.*', ['0.2.1', '1.0.4'], ['1.1.0']];
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $denied
     */
    #[DataProvider('cases')]
    public function allowsExactlyTheVersionsOfTheConstraint(string $constraint, array $allowed, array $denied): void
    {
        $parsed = VersionConstraint::parse($constraint);

        foreach ($allowed as $version) {
            Assert::true($parsed->allows($version));
        }

        foreach ($denied as $version) {
            Assert::false($parsed->allows($version));
        }
    }

    public function namesTheExactVersionForSelfUpdate(): void
    {
        Assert::same(VersionConstraint::parse("1.2.3\n")->exact(), '1.2.3');
        Assert::same(VersionConstraint::parse('=v1.2.3')->exact(), '1.2.3');
        Assert::null(VersionConstraint::parse('^1.2')->exact());
        Assert::null(VersionConstraint::parse('1.2')->exact());
    }

    public function rejectsGarbage(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Cannot read `latest`');

        VersionConstraint::parse('latest');
    }
}
