<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Analysis;

use Internal\Path;
use Opmin\Module\Analysis\FileReferences;
use Opmin\Module\Analysis\Flag;
use Opmin\Module\Analysis\ReferenceCollector;
use Opmin\Module\Analysis\ReferenceIndex;
use Opmin\Module\Common\Cache\FileStore;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Project\Project;
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
     * What may write a property bypassing its class: [code, closure writes, reinitializes,
     * reflection writes, walks objects].
     *
     * @return iterable<string, array{string, list<string>, bool, bool, bool}>
     */
    public static function propertyWrites(): iterable
    {
        yield 'nothing' => ['class A { function __construct() { $this->p = 1; } function f() { $this->q = 2; } }', [], false, false, false];
        yield 'closure assigns' => ['$f = function ($o) { $o->p = 1; };', ['p'], false, false, false];
        yield 'arrow function increments' => ['$f = fn($o) => $o->n++;', ['n'], false, false, false];
        yield 'closure references' => ['$f = function ($o) { $r = &$o->items; };', ['items'], false, false, false];
        yield 'closure passes by reference' => ['$f = function ($o) { sort($o->list); };', ['list'], false, false, false];
        yield 'closure passes by value' => ['$f = function ($o) { return strlen($o->name); };', [], false, false, false];
        yield 'closure passes to a method' => ['$f = function ($o) { $o->m($o->name); };', ['name'], false, false, false];
        yield 'closure writes a dynamic name' => ['$f = function ($o, $n) { $o->$n = 1; };', ['*'], false, false, false];
        yield 'closure iterates an object by reference' => ['$f = function ($o) { foreach ($o as &$v) {} };', ['*'], false, false, false];
        yield 'closure unsets' => ['$f = function ($o) { unset($o->p); };', ['p'], false, false, false];
        yield 'method writes, not a closure' => ['class A { function f($o) { $o->p = 1; } }', [], false, false, false];
        yield 'constructor called again' => ['$o->__construct();', [], true, false, false];
        yield 'own constructor called again' => ['class A { function reset() { $this->__construct(); } }', [], true, false, false];
        yield 'parent constructor in a constructor' => ['class A extends B { function __construct() { parent::__construct(); } }', [], false, false, false];
        yield 'parent constructor elsewhere' => ['class A extends B { function reset() { parent::__construct(); } }', [], true, false, false];
        yield 'unserialize called' => ['$o->__unserialize([]);', [], true, false, false];
        yield 'constructor as a callable' => ['call_user_func([$o, "__construct"]);', [], true, false, false];
        yield 'constructor through reflection' => ['(new \ReflectionClass($c))->getConstructor()->invoke($o);', [], false, true, false];
        yield 'reflection writes a property' => ['$p->setValue($o, 1);', [], false, true, false];
        yield 'lazy object reset' => ['$r->resetAsLazyGhost($o, $init);', [], false, true, false];
        yield 'array_walk of a variable' => ['array_walk($o, $f);', [], false, false, true];
        yield 'array_walk of an array literal' => ['array_walk([1, 2], $f);', [], false, false, false];
        yield 'ArrayObject of a variable' => ['new \ArrayObject($o);', [], false, false, true];
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

    /**
     * @param list<string> $closureWrites
     */
    #[DataProvider('propertyWrites')]
    public function collectsWhatWritesPropertiesBypassingTheClass(
        string $code,
        array $closureWrites,
        bool $reinitializes,
        bool $reflectionWrites,
        bool $walksObjects,
    ): void {
        $index = new ReferenceIndex();
        $index->add('src/a.php', "<?php {$code}");

        $effects = $index->effects();

        Assert::same(
            [$effects->closureWrites, $effects->reinitializes, $effects->reflectionWrites, $effects->walksObjects],
            [$closureWrites, $reinitializes, $reflectionWrites, $walksObjects],
        );
    }

    public function effectsOfFilesAreMerged(): void
    {
        $index = new ReferenceIndex();
        $index->add('src/a.php', '<?php $f = fn($o) => $o->b = 1; new \ReflectionClass(\App\A::class);');
        $index->add('src/b.php', '<?php $f = fn($o) => $o->a = 1; $o->__wakeup();');

        $effects = FileReferences::fromArray(\json_decode((string) \json_encode($index->effects()->toArray()), true));

        Assert::same($effects->closureWrites, ['a', 'b']);
        Assert::same($effects->reflectedClasses, ['app\a']);
        Assert::true($effects->reinitializes);
    }

    public function dynamicCallInsideAClassReachesItsOwnMethods(): void
    {
        $index = new ReferenceIndex();
        $index->add('src/Svc.php', '<?php namespace App; class Svc { function f($m) { return $this->$m(); } function run() {} }');

        Assert::same($index->flagsFor('App\Svc::run', UnitKind::Method), [Flag::CalledDynamically]);
        Assert::same($index->flagsFor('App\Other::run', UnitKind::Method), []);
    }

    public function aFileWithNonUtf8StringsIsIndexedWithoutTheCache(): void
    {
        $cache = (string) \realpath(\sys_get_temp_dir()) . '/opmin-refs-' . \bin2hex(\random_bytes(4));
        # A Latin-1 byte is a valid part of a PHP name, but not UTF-8.
        $code = "<?php call_user_func(\"App\\\\caf\xE9\"); \$bytes = \"\xFF\xFE\";";

        $first = new ReferenceIndex(new FileStore(Path::create($cache)));
        $first->add('legacy.php', $code);
        $second = new ReferenceIndex(new FileStore(Path::create($cache)));
        $second->add('legacy.php', $code);

        Assert::same($first->flagsFor("App\\caf\xE9", UnitKind::Function), [Flag::CalledDynamically]);
        Assert::same($second->flagsFor("App\\caf\xE9", UnitKind::Function), [Flag::CalledDynamically]);
        \exec('rm -rf ' . \escapeshellarg($cache));
    }

    public function unparsableFileIsSkipped(): void
    {
        $index = new ReferenceIndex();
        $index->add('broken.php', '<?php call_user_func("App\\\\helper"');

        Assert::same($index->flagsFor('App\helper', UnitKind::Function), []);
    }

    public function hiddenDirectoriesAreNotScanned(): void
    {
        $dir = self::project([
            'src/Svc.php' => '<?php namespace App; class Svc { function run() {} function other() {} }',
            'tests/SvcTest.php' => '<?php $f = $svc->run(...);',
            '.agents/skills/scan.php' => '<?php new \ReflectionClass($class); $fn(); $object->$method();',
        ]);

        $index = ReferenceIndex::build(new Project(Path::create($dir), true, null), null, null);

        Assert::same($index->flagsFor('App\Svc::run', UnitKind::Method), [Flag::CalledDynamically]);
        Assert::same($index->flagsFor('App\Svc::other', UnitKind::Method), []);
        Assert::same($index->flagsFor('App\helper', UnitKind::Function), []);
        \exec('rm -rf ' . \escapeshellarg($dir));
    }

    public function cacheDirIsNotScanned(): void
    {
        $dir = self::project([
            'src/Svc.php' => '<?php namespace App; class Svc { function run() {} }',
            'build/opmin/rector/cache/entry.php' => '<?php new \ReflectionClass($class); $fn();',
        ]);

        $index = ReferenceIndex::build(new Project(Path::create($dir), true, null), Path::create("{$dir}/build/opmin"), null);

        Assert::same($index->flagsFor('App\Svc::run', UnitKind::Method), []);
        Assert::same($index->flagsFor('App\helper', UnitKind::Function), []);
        \exec('rm -rf ' . \escapeshellarg($dir));
    }

    /**
     * @param array<non-empty-string, string> $files Contents by path relative to the project root.
     * @return non-empty-string The project root.
     */
    private static function project(array $files): string
    {
        $dir = (string) \realpath(\sys_get_temp_dir()) . '/opmin-refs-' . \bin2hex(\random_bytes(4));
        foreach ($files as $file => $content) {
            @\mkdir(\dirname("{$dir}/{$file}"), 0777, true);
            \file_put_contents("{$dir}/{$file}", $content);
        }

        return $dir;
    }
}
