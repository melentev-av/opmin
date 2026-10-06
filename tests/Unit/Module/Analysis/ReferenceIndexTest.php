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
