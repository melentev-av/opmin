<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize;

use Opmin\Module\Optimize\Units;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Units::class)]
final class UnitsTest
{
    private const BEFORE = <<<'PHP'
        <?php
        namespace App;

        final class Svc
        {
            /** Adds. */
            public function add(int $a, int $b): int
            {
                return \array_sum([$a, $b]);
            }

            public function map(array $a): array
            {
                return array_map(fn($x) => strlen($x), $a);
            }

            public function same(): int
            {
                return 1;
            }
        }
        PHP;
    private const AFTER = <<<'PHP'
        <?php
        namespace App;

        final class Svc
        {
            /** Adds two numbers. */
            public function add(int $a, int $b): int
            {
                return $a + $b;
            }

            public function map(array $a): array
            {
                return \array_map(fn($x) => \strlen($x), $a);
            }

            public function same(): int
            {
                return 1;
            }
        }
        PHP;

    public function changedTopLevelUnitsIncludeClosuresAndDocblocks(): void
    {
        $before = Units::of(self::BEFORE, 'src/Svc.php');

        $changed = $before->changedTopLevel(Units::of(self::AFTER, 'src/Svc.php'));

        Assert::same($changed, ['App\Svc::add', 'App\Svc::map']);
        Assert::same($before->topLevel('App\Svc::map::{closure:1}'), 'App\Svc::map');
        Assert::same($before->topLevel('src/Svc.php::<main>::{closure:1}'), null);
        Assert::same($before->family('App\Svc::map'), ['App\Svc::map', 'App\Svc::map::{closure:1}']);
    }

    public function takesUnitsWithTheirDocblocksFromAnotherVersion(): void
    {
        $before = Units::of(self::BEFORE, 'src/Svc.php');
        $after = Units::of(self::AFTER, 'src/Svc.php');

        $mixed = $before->withUnitsFrom($after, ['App\Svc::map', 'App\Svc::add']);
        $onlyMap = $before->withUnitsFrom($after, ['App\Svc::map']);

        Assert::same($mixed, self::AFTER);
        Assert::string((string) $onlyMap)->contains('/** Adds. */')->contains('return \array_sum([$a, $b]);')->contains('\array_map(fn($x) => \strlen($x), $a)');
        Assert::same($before->withUnitsFrom($after, ['App\Svc::missing']), null);
        Assert::same(Units::of(self::AFTER, 'src/Svc.php')->source('App\Svc::same'), "public function same(): int\n    {\n        return 1;\n    }");
        Assert::same($after->docComment('App\Svc::add'), '/** Adds two numbers. */');
    }

    public function brokenCodeHasNoUnits(): void
    {
        Assert::same(Units::of('<?php function (', 'x.php')->units, []);
    }
}
