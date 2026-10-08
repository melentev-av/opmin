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

    public function replacesTheSourceOfOneUnit(): void
    {
        $before = Units::of(self::BEFORE, 'src/Svc.php');
        $indented = "    public function same(): int\n    {\n        return 2;\n    }";
        $flush = "public function same(): int\n{\n    return 2;\n}";
        $withDoc = "/** Two. */\n    public function add(int \$a, int \$b): int\n    {\n        return \$a + \$b;\n    }";

        Assert::same($before->withSource('App\Svc::same', $indented), \str_replace("return 1;\n    }\n}", "return 2;\n    }\n}", self::BEFORE));
        # A source written from the line start gets the indentation of the unit.
        Assert::same($before->withSource('App\Svc::same', $flush), $before->withSource('App\Svc::same', $indented));
        # Without a docblock the original one stays; with one it is replaced.
        Assert::string((string) $before->withSource('App\Svc::add', "public function add(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}"))
            ->contains("/** Adds. */\n    public function add(int \$a, int \$b): int\n    {\n        return \$a + \$b;\n    }");
        Assert::string((string) $before->withSource('App\Svc::add', $withDoc))
            ->contains("    /** Two. */\n    public function add(")
            ->notContains('Adds.');
        Assert::same($before->withSource('App\Svc::missing', $indented), null);
        Assert::same($before->indent('App\Svc::add'), '    ');
    }

    public function aHeredocIsNeverReindented(): void
    {
        $before = Units::of(self::BEFORE, 'src/Svc.php');
        $heredoc = "public function same(): string\n{\n    return <<<TXT\n    a\n    TXT;\n}";

        Assert::string((string) $before->withSource('App\Svc::same', $heredoc))->contains("    public function same(): string\n{\n    return <<<TXT\n    a\n    TXT;\n}");
    }

    public function brokenCodeHasNoUnits(): void
    {
        Assert::same(Units::of('<?php function (', 'x.php')->units, []);
    }
}
