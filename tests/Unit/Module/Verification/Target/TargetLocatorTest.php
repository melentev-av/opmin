<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Target;

use Opmin\Module\Analysis\Flag;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Verification\Target\Target;
use Opmin\Module\Verification\Target\TargetLocator;
use Opmin\Module\Verification\Target\UnsupportedTarget;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(TargetLocator::class)]
#[Covers(Target::class)]
final class TargetLocatorTest
{
    private const CODE = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace App;

        use App\Ports\Clock;
        use function strlen as len;

        function top(int $a) { static $n; return $a; }

        final class Svc
        {
            public function run(int $x) { return fn(int $y) => $x + $y + $this->k; }
            public static function make() { return static function () use (&$z) { return 1; }; }
            private function hidden() { return 1; }
            public function __construct() {}
            public string $name { get => 'n'; set => $value; }
        }

        trait Greets { public function hi() { return 'hi'; } public static function bye() { return 'bye'; } }

        $main = function ($a) { return $a; };
        $anon = new class { public function m() { return function () { return 1; }; } };
        abstract class Base { abstract public function todo(); }
        PHP;

    /**
     * @return iterable<string, array{non-empty-string, UnitKind, array<string, mixed>, string|null, list<Flag>}>
     */
    public static function targets(): iterable
    {
        yield 'function' => ['App\top', UnitKind::Function, ['kind' => 'function', 'name' => 'App\top'], null, [Flag::StaticVar]];
        yield 'instance method' => ['App\Svc::run', UnitKind::Method, ['kind' => 'method', 'class' => 'App\Svc', 'name' => 'run'], 'App\Svc', []];
        yield 'static method' => ['App\Svc::make', UnitKind::Method, ['kind' => 'method', 'class' => 'App\Svc', 'name' => 'make'], null, []];
        yield 'private method' => ['App\Svc::hidden', UnitKind::Method, ['kind' => 'method', 'class' => 'App\Svc', 'name' => 'hidden'], 'App\Svc', []];
        yield 'constructor' => ['App\Svc::__construct', UnitKind::Method, ['kind' => 'method', 'class' => 'App\Svc', 'name' => '__construct'], null, []];
        yield 'hook' => ['App\Svc::$name::set', UnitKind::Hook, ['kind' => 'hook', 'class' => 'App\Svc', 'property' => 'name', 'hook' => 'set'], 'App\Svc', []];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupported(): iterable
    {
        yield 'missing' => ['App\nope', 'is not in the file'];
        yield 'main code' => ['src/Svc.php::<main>', 'Main code'];
        yield 'abstract method' => ['App\Base::todo', 'has no body'];
        yield 'method of an anonymous class' => ['src/Svc.php::<main>::{class:1}::m', 'anonymous classes'];
        yield 'closure in an anonymous class' => ['src/Svc.php::<main>::{class:1}::m::{closure:1}', 'anonymous classes'];
    }

    /**
     * @param non-empty-string $key
     * @param array<string, mixed> $call
     * @param list<Flag> $flags
     */
    #[DataProvider('targets')]
    public function describesHowToCall(string $key, UnitKind $kind, array $call, ?string $receiver, array $flags): void
    {
        $target = (new TargetLocator())->locate(self::CODE, $key, 'src/Svc.php');

        Assert::same([$target->key, $target->kind, $target->call, $target->receiver, $target->flags, $target->namespace, $target->wrapper], [$key, $kind, $call, $receiver, $flags, 'App', null]);
    }

    public function arrowFunctionWrapperKeepsLinesImportsAndFreeVariables(): void
    {
        $target = (new TargetLocator())->locate(self::CODE, 'App\Svc::run::{closure:1}', 'src/Svc.php');
        $lines = \explode("\n", (string) $target->wrapper);
        $name = 'opmin_closure_' . \substr(\hash('sha256', 'App\Svc::run::{closure:1}'), 0, 12);

        Assert::same($target->call, ['kind' => 'closure', 'wrapper' => "App\\{$name}", 'scope' => 'App\Svc']);
        Assert::same($target->receiver, 'App\Svc');
        Assert::same($lines[0], '<?php declare(strict_types=1); namespace App; use App\Ports\Clock; use function strlen as len;');
        Assert::same($lines[11], "function {$name}(\$x) { return fn(int \$y) => \$x + \$y + \$this->k; }");
        Assert::same(\count($lines), 13);
    }

    public function staticClosureWithByRefUseHasNoReceiver(): void
    {
        $target = (new TargetLocator())->locate(self::CODE, 'App\Svc::make::{closure:1}', 'src/Svc.php');

        Assert::null($target->receiver);
        Assert::string((string) $target->wrapper)->contains('($z) { return static function () use (&$z) { return 1; }; }');
    }

    public function closureInMainCodeOfAFile(): void
    {
        $target = (new TargetLocator())->locate(self::CODE, 'src/Svc.php::<main>::{closure:1}', 'src/Svc.php');

        Assert::same([$target->receiver, $target->call['scope'] ?? null], [null, null]);
        Assert::string((string) $target->wrapper)->contains('function opmin_closure_');
    }

    public function traitMethodsGoThroughAUsingClass(): void
    {
        $hi = (new TargetLocator())->locate(self::CODE, 'App\Greets::hi', 'src/Svc.php');
        $bye = (new TargetLocator())->locate(self::CODE, 'App\Greets::bye', 'src/Svc.php');
        $user = 'App\OpminTraitUser' . \substr(\hash('sha256', 'App\Greets::hi'), 0, 12);

        Assert::same($hi->call, ['kind' => 'method', 'class' => $user, 'name' => 'hi']);
        Assert::same($hi->receiver, $user);
        Assert::string((string) $hi->wrapper)->contains('final class OpminTraitUser');
        Assert::string((string) $hi->wrapper)->contains('{ use \App\Greets; }');
        Assert::null($bye->receiver);
    }

    public function globalNamespaceNeedsNoNamespaceStatement(): void
    {
        $code = "<?php\nfunction outer() {\n    return function () { return 1; };\n}\n";

        $target = (new TargetLocator())->locate($code, 'outer::{closure:1}', 'x.php');

        Assert::same($target->namespace, '');
        Assert::same(\explode("\n", (string) $target->wrapper)[0], '<?php');
        Assert::same($target->call['wrapper'], 'opmin_closure_' . \substr(\hash('sha256', 'outer::{closure:1}'), 0, 12));
    }

    #[DataProvider('unsupported')]
    public function refusesWhatTheHarnessCannotCall(string $key, string $message): never
    {
        Expect::exception(UnsupportedTarget::class)->withMessageContaining($message);

        /** @var non-empty-string $key */
        (new TargetLocator())->locate(self::CODE, $key, 'src/Svc.php');
    }

    public function syntaxErrorIsUnsupported(): never
    {
        Expect::exception(UnsupportedTarget::class)->withMessageContaining('Syntax error');

        (new TargetLocator())->locate('<?php function f( {', 'f', 'x.php');
    }
}
