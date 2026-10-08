<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Opcode\Locate;

use Opmin\Module\Opcode\Locate\CodeUnit;
use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Opcode\Locate\LocateException;
use Opmin\Module\Opcode\Locate\UnitKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FunctionLocator::class)]
#[Covers(CodeUnit::class)]
final class FunctionLocatorTest
{
    public function listsUnitsInDumpOrder(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            class Outer {
                public function a() { return new class { public function x() {} }; }
                public function b() {}
            }
            function f() { $c = fn() => 1; }
            $m = function () {};
            PHP;

        $keys = self::keys($code);

        Assert::same($keys, [
            'a.php::<main>',
            'a.php::<main>::{closure:1}',
            'App\f',
            'App\f::{closure:1}',
            # The anonymous class completes before the class around it.
            'App\Outer::a::{class:1}::x',
            'App\Outer::a',
            'App\Outer::b',
        ]);
    }

    public function numbersClosuresWithinTheirParent(): void
    {
        $code = <<<'PHP'
            <?php
            function f() {
                $a = function () { $inner = fn() => fn() => 1; };
                $b = static fn() => 2;
            }
            PHP;

        $keys = self::keys($code);

        Assert::same($keys, [
            'a.php::<main>',
            'f',
            'f::{closure:1}',
            'f::{closure:1}::{closure:1}',
            'f::{closure:1}::{closure:1}::{closure:1}',
            'f::{closure:2}',
        ]);
    }

    public function closureKeysDoNotDependOnLines(): void
    {
        $before = self::keys("<?php\nfunction f() {\n    \$a = fn() => 1;\n}\n");
        $after = self::keys("<?php\n\n\n// moved\nfunction f() {\n\n    \$a = fn() => 1;\n}\n");

        Assert::same($after, $before);
    }

    public function listsHooksAfterMethodsGetBeforeSet(): void
    {
        $code = <<<'PHP'
            <?php
            class P {
                public string $name { set(string $v) { $this->name = $v; } get => $this->name; }
                public function __construct(public int $age { get => $this->age; }) {}
                public function m() {}
            }
            PHP;

        $units = (new FunctionLocator())->locate($code, 'a.php::<main>');

        Assert::same(\array_map(static fn(CodeUnit $u): string => $u->key, $units), [
            'a.php::<main>', 'P::__construct', 'P::m', 'P::$name::get', 'P::$name::set', 'P::$age::get',
        ]);
        Assert::same($units[3]->kind, UnitKind::Hook);
        Assert::same($units[3]->dumpName, 'P::$name::get');
    }

    public function marksAbstractAndInterfaceMethods(): void
    {
        $code = "<?php\ninterface I { function i(); }\nabstract class A { abstract function a(); function b() {} }\n";

        $units = (new FunctionLocator())->locate($code, 'a.php::<main>');

        Assert::same(
            \array_map(static fn(CodeUnit $u): array => [$u->key, $u->abstract], $units),
            [['a.php::<main>', false], ['I::i', true], ['A::a', true], ['A::b', false]],
        );
    }

    public function namesAnonymousClassMethodsForTheDump(): void
    {
        $units = (new FunctionLocator())->locate("<?php\n\$o = new class extends \\Exception { function m() {} };\n", 'a.php::<main>');

        Assert::same($units[1]->key, 'a.php::<main>::{class:1}::m');
        Assert::same($units[1]->dumpName, '@anonymous::m');
        Assert::true($units[1]->isAnonymousClassMethod());
    }

    public function disambiguatesConditionalDeclarationsOfOneFunction(): void
    {
        $keys = self::keys("<?php\nif (PHP_OS === 'x') { function f() {} } else { function f() {} }\n");

        Assert::same($keys, ['a.php::<main>', 'f', 'f#2']);
    }

    public function rejectsSyntaxError(): never
    {
        Expect::exception(LocateException::class)->withMessageContaining('Syntax error');

        (new FunctionLocator())->locate("<?php\nfunction (\n", 'a.php::<main>');
    }

    /**
     * @return list<string>
     */
    private static function keys(string $code): array
    {
        return \array_map(
            static fn(CodeUnit $u): string => $u->key,
            (new FunctionLocator())->locate($code, 'a.php::<main>'),
        );
    }
}
