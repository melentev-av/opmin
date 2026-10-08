<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Analysis\Shadow;

use Opmin\Module\Analysis\Shadow\FileShadows;
use Opmin\Module\Analysis\Shadow\ShadowCollector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The exact data a file contributes to the shadow index: names normalized, sorted, mocks keyed.
 */
#[Test]
#[Covers(ShadowCollector::class)]
#[Covers(FileShadows::class)]
final class ShadowCollectorTest
{
    private const CLOCK = ['date', 'date_create', 'date_create_immutable', 'getdate', 'gmdate', 'gmmktime', 'hrtime', 'idate', 'localtime', 'microtime', 'mktime', 'sleep', 'strtotime', 'time', 'usleep'];

    /**
     * [code, functions, constants, mocks].
     *
     * @return iterable<string, array{string, list<string>, list<string>, list<array{?string, ?string}>}>
     */
    public static function files(): iterable
    {
        yield 'functions sorted and lower-cased' => ['namespace App\Sub; function Zeta() {} function alpha() {}', ['app\sub\alpha', 'app\sub\zeta'], [], []];
        yield 'global function' => ['function Helper() {}', ['helper'], [], []];
        yield 'constants: namespace lower-cased, name kept' => ['namespace App\Sub; const B = 1, A = 2; define("\\\\Other\\\\X", 1); define("Y", 2);', [], ['Y', 'app\sub\A', 'app\sub\B', 'other\X'], []];
        yield 'define with a runtime name is ignored' => ['define($name, 1); define("", 2); DEFINE("Z", 3);', [], ['Z'], []];
        yield 'class constants are not namespace constants' => ['namespace App; class C { const X = 1; } interface I { const Y = 2; }', [], [], []];
        yield 'a function call named like define is not define' => ['namespace App; undefine("A", 1);', [], [], []];
        yield 'php-mock with a leading backslash in the name' => ['$this->getFunctionMock(null, "\\\\App\\\\Clock\\\\Time");', [], [], [['app\clock', 'time']]];
        yield 'php-mock trims and lower-cases the namespace' => ['PHPMock::defineFunctionMock("\\\\App\\\\Clock\\\\", "Time");', [], [], [['app\clock', 'time']]];
        yield 'php-mock with an empty name mocks any function' => ['$this->getFunctionMock("App", "");', [], [], [['app', null]]];
        yield 'php-mock of the second argument only' => ['$this->defineFunctionMock($ns, "rand");', [], [], [[null, 'rand']]];
        yield 'mocks keyed: the same mock once' => ['PHPMock::defineFunctionMock("App", "rand"); PHPMock::defineFunctionMock("app", "RAND");', [], [], [['app', 'rand']]];
        yield 'mocks sorted by namespace and function' => ['PHPMock::defineFunctionMock("B", "x"); PHPMock::defineFunctionMock("A", "y"); PHPMock::defineFunctionMock("A", "b");', [], [], [['a', 'b'], ['a', 'y'], ['b', 'x']]];
        yield 'not php-mock: another static method' => ['Other::registerMock("App", "time"); ClockMock::withClockMock(true);', [], [], []];
        yield 'mock() of another class than PHPMockery' => ['Mockery::mock("App", "time");', [], [], []];
        yield 'new Mock of another class' => ['new \Other\Mock("App", "time");', [], [], []];
        yield 'MockBuilder chain with name before namespace' => ['$m = $b->setName("Time")->setFunction(fn() => 1)->setNamespace("App")->build();', [], [], [['app', 'time']]];
        yield 'a chain without setNamespace' => ['$b->setName("time")->build();', [], [], []];
        yield 'ClockMock of a class in Tests' => ['ClockMock::register(\App\Tests\Unit\FooTest::class);', [], [], [...self::clock('app\tests\unit'), ...self::clock('app\unit')]];
        yield 'DnsMock of a literal class name with a leading backslash' => ['DnsMock::register("\\\\App\\\\Net");', [], [], \array_map(static fn(string $f): array => ['app', $f], ['checkdnsrr', 'dns_check_record', 'dns_get_mx', 'dns_get_record', 'gethostbyaddr', 'gethostbyname', 'gethostbynamel', 'getmxrr'])];
        yield 'ClockMock of a global class' => ['ClockMock::register(\Foo::class);', [], [], self::clock('')];
        yield 'ClockMock of __CLASS__ in a nested class keeps the enclosing one' => ['namespace App\Tests; class Outer { function f() { new class {}; ClockMock::register(__CLASS__); } }', [], [], [...self::clock('app\tests'), ...self::clock('app')]];
        yield 'ClockMock of static::class' => ['namespace Lib; class T { function f() { ClockMock::register(static::class); } }', [], [], self::clock('lib')];
        yield 'ClockMock outside a class with self::class' => ['ClockMock::register(self::class);', [], [], self::clock(null)];
        yield 'time-sensitive on a class without namespace' => ["/** @group time-sensitive */\nclass FooTest {}", [], [], self::clock('')];
        yield 'another attribute does not group' => ["namespace App;\n#[Other('time-sensitive')]\nclass FooTest {}", [], [], []];
        yield 'eval of a literal with a constant' => ['eval("namespace App; function f() {} const X = 1;");', ['app\f'], ['app\X'], []];
        yield 'eval with a case-insensitive FUNCTION keyword' => ['eval($pre . "namespace App;\nFUNCTION Foo() {}");', [], [], [['app', 'foo']]];
        yield 'eval of interpolated code without a namespace keyword' => ['eval("function $name() {}");', [], [], [['', null]]];
        yield 'eval with namespace keyword but no literal namespace' => ['eval("namespace " . $ns . "; function f() {}");', [], [], [[null, 'f']]];
        yield 'eval of code without functions' => ['eval($code . "; return 1;");', [], [], []];
    }

    #[DataProvider('files')]
    public function collectsTheExactShadows(string $code, array $functions, array $constants, array $mocks): void
    {
        $shadows = (new ShadowCollector())->collect("<?php\n{$code}");

        Assert::same($shadows->toArray(), ['functions' => $functions, 'constants' => $constants, 'mocks' => $mocks]);
        Assert::same($shadows->isEmpty(), $functions === [] && $constants === [] && $mocks === []);
    }

    public function oneCollectorServesManyFiles(): void
    {
        $collector = new ShadowCollector();

        $collector->collect('<?php namespace A; class C { function f() { PHPMock::defineFunctionMock("A", "x"); } } function g() {}');
        $second = $collector->collect('<?php function h() {}');

        Assert::same($second->toArray(), ['functions' => ['h'], 'constants' => [], 'mocks' => []]);
    }

    public function invalidPhpDeclaresNothing(): void
    {
        $shadows = (new ShadowCollector())->collect('<?php namespace App; function (');

        Assert::true($shadows->isEmpty());
    }

    public function isEmptyNeedsAllThreeEmpty(): void
    {
        Assert::false((new FileShadows([], ['X']))->isEmpty());
        Assert::false((new FileShadows([], [], [[null, null]]))->isEmpty());
        Assert::false((new FileShadows(['f']))->isEmpty());
        Assert::true((new FileShadows())->isEmpty());
    }

    /**
     * @return list<array{?string, string}>
     */
    private static function clock(?string $namespace): array
    {
        return \array_map(static fn(string $f): array => [$namespace, $f], self::CLOCK);
    }
}
