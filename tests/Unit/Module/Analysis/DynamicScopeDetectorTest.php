<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Analysis;

use Opmin\Module\Analysis\DynamicScopeDetector;
use Opmin\Module\Analysis\Flag;
use Opmin\Module\Analysis\Restriction;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DynamicScopeDetector::class)]
#[Covers(Flag::class)]
final class DynamicScopeDetectorTest
{
    /**
     * One case per row of table 2.2 of the brief, plus the sources of nondeterminism of 2.5.
     *
     * @return iterable<string, array{string, list<Flag>}>
     */
    public static function constructs(): iterable
    {
        yield 'compact' => ['$a = 1; return compact("a");', [Flag::Compact]];
        yield 'compact fully qualified' => ['$a = 1; return \compact("a");', [Flag::Compact]];
        yield 'extract' => ['extract($data); return $a;', [Flag::Extract]];
        yield 'variable variable' => ['$name = "a"; return $$name;', [Flag::VariableVariable]];
        yield 'variable by string' => ['return ${"a"};', [Flag::VariableVariable]];
        yield 'get_defined_vars' => ['$a = 1; return get_defined_vars();', [Flag::DefinedVars]];
        yield 'func_get_args' => ['return func_get_args();', [Flag::FuncArgs]];
        yield 'func_get_arg' => ['return func_get_arg(0);', [Flag::FuncArgs]];
        yield 'func_num_args' => ['return func_num_args();', [Flag::FuncArgs]];
        yield 'debug_backtrace' => ['return debug_backtrace();', [Flag::Backtrace]];
        yield 'debug_print_backtrace' => ['debug_print_backtrace();', [Flag::Backtrace]];
        yield 'exception trace' => ['return (new \Exception())->getTrace();', [Flag::Backtrace]];
        yield 'trace as string' => ['return $e->getTraceAsString();', [Flag::Backtrace]];
        yield '__LINE__' => ['return __LINE__;', [Flag::MagicConstant]];
        yield '__FUNCTION__' => ['return __FUNCTION__;', [Flag::MagicConstant]];
        yield '__METHOD__' => ['return __METHOD__;', [Flag::MagicConstant]];
        yield 'eval' => ['return eval("return 1;");', [Flag::Eval]];
        yield 'include' => ['return include __DIR__ . "/x.php";', [Flag::Include]];
        yield 'require_once' => ['require_once "x.php";', [Flag::Include]];
        yield 'static variable' => ['static $n = 0; return ++$n;', [Flag::StaticVar]];
        yield 'global' => ['global $config; return $config;', [Flag::Global]];
        yield '$GLOBALS' => ['return $GLOBALS["config"];', [Flag::Global]];
        yield 'isset on a property' => ['return isset($o->x);', [Flag::Magic]];
        yield 'coalesce on a property' => ['return $o->x ?? null;', [Flag::Magic]];
        yield 'empty on a nullsafe property' => ['return empty($o?->x);', [Flag::Magic]];
        yield 'unset of a property' => ['unset($o->x);', [Flag::Magic]];
        yield 'reflection of a static class' => ['return new \ReflectionClass(static::class);', [Flag::Reflection]];
        yield 'reflection of $this' => ['return new \ReflectionObject($this);', [Flag::Reflection]];
        yield 'reflection of the function' => ['return new \ReflectionFunction(__FUNCTION__);', [Flag::MagicConstant, Flag::Reflection]];
        yield 'file I/O' => ['return file_get_contents("/etc/hosts");', [Flag::Io]];
        yield 'curl' => ['return curl_init();', [Flag::Io]];
        yield 'PDO' => ['return new \PDO("sqlite::memory:");', [Flag::Io]];
        yield 'time' => ['return time();', [Flag::Time]];
        yield 'date' => ['return date("Y");', [Flag::Time]];
        yield 'new DateTime' => ['return new \DateTimeImmutable();', [Flag::Time]];
        yield 'random_int' => ['return random_int(1, 6);', [Flag::Random]];
        yield 'shuffle' => ['shuffle($a); return $a;', [Flag::Random]];
        yield 'Randomizer without engine' => ['return new \Random\Randomizer();', [Flag::Random]];
        yield 'getenv' => ['return getenv("HOME");', [Flag::Environment]];
        yield 'memory_get_usage' => ['return memory_get_usage();', [Flag::Environment]];
        yield 'several, in declaration order' => ['static $n; return compact("n") + func_get_args();', [Flag::Compact, Flag::FuncArgs, Flag::StaticVar]];
    }

    /**
     * Code that looks dynamic but is not, or is a unit of its own.
     *
     * @return iterable<string, array{string}>
     */
    public static function staticConstructs(): iterable
    {
        yield 'namespaced function with the same name' => ['return Other\compact("a");'];
        yield 'method named compact' => ['return $this->compact("a");'];
        yield 'isset on an array' => ['return isset($a["x"]) ? $a["x"] : null;'];
        yield 'static property' => ['return self::$cache ?? null;'];
        yield 'closure with compact' => ['$f = function () { $a = 1; return compact("a"); }; return $f;'];
        yield 'arrow function with func_get_args' => ['return fn() => func_get_args();'];
        yield 'anonymous class with static var' => ['return new class { public function f() { static $n; } };'];
        yield 'DateTime with a fixed date' => ['return new \DateTimeImmutable("2020-01-02 03:04:05");'];
        yield 'Randomizer with an engine' => ['return new \Random\Randomizer(new \Random\Engine\Mt19937(1));'];
        yield 'reflection of another class' => ['return new \ReflectionClass(Other::class);'];
        yield 'plain code' => ['$s = 0; foreach ($xs as $x) { $s += $x; } return $s;'];
    }

    /**
     * @param list<Flag> $expected
     */
    #[DataProvider('constructs')]
    public function flagsConstruct(string $body, array $expected): void
    {
        $flags = (new DynamicScopeDetector())->detect(self::function($body));

        Assert::same($flags, $expected);
    }

    #[DataProvider('staticConstructs')]
    public function ignoresStaticConstruct(string $body): void
    {
        $flags = (new DynamicScopeDetector())->detect(self::function($body));

        Assert::same($flags, []);
    }

    public function flagsMethodsOfMagicClasses(): void
    {
        [$class, $method] = self::method('class Bag { public function __get($n) {} public function f() { return 1; } }', 'f');
        [$accessClass, $accessMethod] = self::method('class Map implements \ArrayAccess { public function f() { return 1; } }', 'f');

        Assert::same((new DynamicScopeDetector())->detect($method, $class), [Flag::Magic]);
        Assert::same((new DynamicScopeDetector())->detect($accessMethod, $accessClass), [Flag::Magic]);
    }

    public function flagsReflectionOfOwnClass(): void
    {
        [$class, $method] = self::method('namespace App; class Svc { public function f() { return new \ReflectionClass(Svc::class); } }', 'f');

        Assert::same((new DynamicScopeDetector())->detect($method, $class), [Flag::Reflection]);
    }

    public function scansParameterDefaults(): void
    {
        Assert::same((new DynamicScopeDetector())->detect(self::function('return $a;', '$a = __LINE__')), [Flag::MagicConstant]);
    }

    public function restrictionsFollowTheBrief(): void
    {
        Assert::same(Flag::restrictionsOf([Flag::DefinedVars]), [Restriction::Variables, Restriction::VariableSet]);
        Assert::same(Flag::restrictionsOf([Flag::FuncArgs]), [Restriction::Signature, Restriction::ReassignParams]);
        Assert::same(Flag::restrictionsOf([Flag::Eval, Flag::Compact]), [Restriction::Variables, Restriction::Skip]);
        Assert::same(Flag::restrictionsOf([Flag::Global]), [Restriction::Globals, Restriction::SideEffecting]);
        Assert::same(Flag::restrictionsOf([Flag::Reflection]), [Restriction::Signature, Restriction::Docblock]);
        Assert::same(Flag::restrictionsOf([Flag::StaticVar]), [Restriction::StaticChain]);
    }

    public function everyFlagHasRestrictions(): void
    {
        foreach (Flag::cases() as $flag) {
            Assert::true($flag->restrictions() !== [], $flag->value);
        }
    }

    public function valuesRoundTrip(): void
    {
        Assert::same(Flag::fromValues(Flag::values([Flag::StaticVar, Flag::Compact, Flag::StaticVar])), [Flag::Compact, Flag::StaticVar]);
        Assert::same(Flag::fromValues(['compact', 'from_the_future']), [Flag::Compact]);
    }

    private static function function(string $body, string $params = '$o = null'): Node\FunctionLike
    {
        $stmts = self::parse("<?php namespace App; function f({$params}) { {$body} }");
        $function = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Function_::class);
        \assert($function instanceof Node\Stmt\Function_);

        return $function;
    }

    /**
     * @return array{Node\Stmt\Class_, Node\Stmt\ClassMethod}
     */
    private static function method(string $code, string $name): array
    {
        $stmts = self::parse("<?php {$code}");
        $class = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
        \assert($class instanceof Node\Stmt\Class_);
        $method = $class->getMethod($name);
        \assert($method !== null);

        return [$class, $method];
    }

    /**
     * @return array<Node>
     */
    private static function parse(string $code): array
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];

        return (new NodeTraverser(new NameResolver()))->traverse($stmts);
    }
}
