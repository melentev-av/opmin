<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Analysis;

use Opmin\Module\Analysis\FileReferences;
use Opmin\Module\Analysis\Flag;
use Opmin\Module\Analysis\ReferenceCollector;
use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Opcode\Locate\UnitKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ReferenceIndex::class)]
#[Covers(ReferenceCollector::class)]
#[Covers(FileReferences::class)]
final class ReferenceIndexTest
{
    /**
     * The called side of a dynamic call: [label, code elsewhere in the project, key, kind, flags].
     *
     * @return iterable<string, array{string, non-empty-string, UnitKind, list<Flag>}>
     */
    public static function references(): iterable
    {
        yield 'function by string' => ['call_user_func("App\\\\helper", 1);', 'App\helper', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'global function by string' => ['array_map("trim_all", $a);', 'trim_all', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'first-class callable of a function' => ['namespace App; $f = helper(...);', 'App\helper', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'variable function call' => ['$fn = $name; $fn();', 'App\anything', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'call_user_func with a variable' => ['call_user_func($callback);', 'App\anything', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'array callable with a class constant' => ['usort($a, [\App\Sorter::class, "compare"]);', 'App\Sorter::compare', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'array callable on an object' => ['$f = [$sorter, "compare"];', 'App\Other::compare', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'Class::method string' => ['$f = "App\\\\Sorter::compare";', 'App\Sorter::compare', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'Laravel Class@method string' => ['Route::get("/", "App\\\\Http\\\\Home@index");', 'App\Http\Home::index', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'first-class callable of a method' => ['$f = $svc->run(...);', 'App\Svc::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'dynamic method name' => ['$svc->$method();', 'App\Svc::anything', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'reflection of a class' => ['new \ReflectionClass(\App\Svc::class);', 'App\Svc::run', UnitKind::Method, [Flag::Reflection]];
        yield 'reflection of an unknown object' => ['new \ReflectionObject($any);', 'App\Svc::run', UnitKind::Method, [Flag::Reflection]];
        yield 'function with a leading backslash' => ['$f = "\\\\App\\\\helper";', 'App\helper', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'mixed-case string names a method' => ['$f = "App\\\\SVC::Run";', 'App\Svc::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'static first-class callable' => ['$f = \App\Svc::run(...);', 'App\Svc::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'nullsafe first-class callable' => ['$f = $svc?->run(...);', 'App\Other::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'dynamic static method' => ['\App\Svc::$name();', 'App\Svc::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'dynamic static method of a variable class' => ['$class::$name();', 'App\Other::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'array callable built with new' => ['$f = [new \App\Svc(), "run"];', 'App\Svc::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'array callable of a class string' => ['$f = ["App\\\\Svc", "run"];', 'App\Svc::run', UnitKind::Method, [Flag::CalledDynamically]];
        yield 'array_filter with a property callback' => ['array_filter($a, $this->filter);', 'App\x', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'callback from a concatenation' => ['usort($a, "cmp_" . $kind);', 'App\x', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'callback from an array element' => ['call_user_func($handlers[0]);', 'App\x', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'callback from a static property' => ['call_user_func(self::$handler);', 'App\x', UnitKind::Function, [Flag::CalledDynamically]];
        yield 'reflection method of a string with method' => ['new \ReflectionMethod("App\\\\Svc::run");', 'App\Svc::other', UnitKind::Method, [Flag::Reflection]];
        yield 'reflection of a method by string' => ['new \ReflectionMethod("App\\\\Svc::run");', 'App\Svc::run', UnitKind::Method, [Flag::CalledDynamically, Flag::Reflection]];
    }

    /**
     * References that do not reach the function.
     *
     * @return iterable<string, array{string, non-empty-string, UnitKind}>
     */
    public static function nonReferences(): iterable
    {
        yield 'another function' => ['call_user_func("App\\\\other");', 'App\helper', UnitKind::Function];
        yield 'array callable of another class' => ['usort($a, [\App\Other::class, "compare"]);', 'App\Sorter::compare', UnitKind::Method];
        yield 'plain data array' => ['$pair = [$a, $b];', 'App\Svc::run', UnitKind::Method];
        yield 'dynamic call of own method' => ['class Other { function f($m) { return $this->$m(); } }', 'App\Svc::run', UnitKind::Method];
        yield 'string that is not a name' => ['echo "Hello, world";', 'App\helper', UnitKind::Function];
        yield 'closures are never called by name' => ['call_user_func($cb);', 'App\f::{closure:1}', UnitKind::Closure];
        yield 'reflection of another class' => ['new \ReflectionClass(\App\Other::class);', 'App\Svc::run', UnitKind::Method];
        yield 'keyed array is not a callable' => ['$f = ["a" => $svc, "b" => "run"];', 'App\Svc::run', UnitKind::Method];
        yield 'three items are not a callable' => ['$f = [$svc, "run", 1];', 'App\Svc::run', UnitKind::Method];
        yield 'array of a variable and a variable' => ['$pair = [$a, $m];', 'App\Svc::run', UnitKind::Method];
        yield 'callable function with a literal callback' => ['usort($a, "strcmp");', 'App\x', UnitKind::Function];
        yield 'callable function with a closure' => ['array_map(fn($x) => $x, $a);', 'App\x', UnitKind::Function];
        yield 'first-class callable of call_user_func' => ['$f = call_user_func(...);', 'App\x', UnitKind::Function];
        yield 'reflection without arguments' => ['new \ReflectionClass();', 'App\Svc::run', UnitKind::Method];
        yield 'not reflection' => ['new \App\Reflector(\App\Svc::class);', 'App\Svc::run', UnitKind::Method];
        yield 'hooks are not called by name' => ['$f = "App\\\\Svc::run";', 'App\Svc::$p::get', UnitKind::Hook];
        yield 'string of an invalid name' => ['$f = "App\\\\Svc::run()";', 'App\Svc::run', UnitKind::Method];
    }

    /**
     * @param non-empty-string $key
     * @param list<Flag> $expected
     */
    #[DataProvider('references')]
    public function flagsTheCalledSide(string $code, string $key, UnitKind $kind, array $expected): void
    {
        $index = new ReferenceIndex();
        $index->add('routes.php', "<?php {$code}");

        Assert::same($index->flagsFor($key, $kind), $expected);
    }

    /**
     * @param non-empty-string $key
     */
    #[DataProvider('nonReferences')]
    public function leavesOtherFunctions(string $code, string $key, UnitKind $kind): void
    {
        $index = new ReferenceIndex();
        $index->add('routes.php', "<?php {$code}");

        Assert::same($index->flagsFor($key, $kind), []);
    }

    public function dynamicCallInsideAClassReachesItsOwnMethods(): void
    {
        $index = new ReferenceIndex();
        $index->add('src/Svc.php', '<?php namespace App; class Svc { function f($m) { return $this->$m(); } function run() {} }');

        Assert::same($index->flagsFor('App\Svc::run', UnitKind::Method), [Flag::CalledDynamically]);
        Assert::same($index->flagsFor('App\Other::run', UnitKind::Method), []);
    }

    public function unparsableFileIsSkipped(): void
    {
        $index = new ReferenceIndex();
        $index->add('broken.php', '<?php call_user_func("App\\\\helper"');

        Assert::same($index->flagsFor('App\helper', UnitKind::Function), []);
    }
}
