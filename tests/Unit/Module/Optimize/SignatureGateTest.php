<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize;

use Opmin\Module\Analysis\Restriction;
use Opmin\Module\Config\Schema;
use Opmin\Module\Optimize\SignatureGate;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(SignatureGate::class)]
final class SignatureGateTest
{
    /**
     * @return iterable<string, array{string, string, Schema\NativeTypesPolicy, ?string}>
     *         [method before, method after (in `class C`), policy, reason]
     */
    public static function changes(): iterable
    {
        $policy = Schema\NativeTypesPolicy::NonOverridable;
        yield 'body only' => ['public function m($a) { return 1; }', 'public function m($a) { return 2; }', $policy, null];
        yield 'renamed parameter breaks named arguments' => ['public function m($a) {}', 'public function m($b) {}', $policy, 'changes the signature'];
        yield 'changed default' => ['public function m($a = 1) {}', 'public function m($a = 2) {}', $policy, 'changes the signature'];
        yield 'by reference' => ['public function m($a) {}', 'public function m(&$a) {}', $policy, 'changes the signature'];
        yield 'one parameter less' => ['public function m($a, $b) {}', 'public function m($a) {}', $policy, 'changes the signature'];
        yield 'type of a private method' => ['private function m($a) {}', 'private function m(int $a) {}', $policy, null];
        yield 'type of a final method' => ['final public function m($a) {}', 'final public function m(int $a) {}', $policy, null];
        yield 'type of a public method' => ['public function m($a) {}', 'public function m(int $a) {}', $policy, 'changes native types of an overridable method (signatures.public_api)'];
        yield 'return type with policy none' => ['private function m() {}', 'private function m(): int {}', Schema\NativeTypesPolicy::None, 'changes native types (signatures.native_types: none)'];
        yield 'public type with policy all' => ['public function m($a) {}', 'public function m(int $a) {}', Schema\NativeTypesPolicy::All, null];
    }

    #[DataProvider('changes')]
    public function decidesOnSignatureChanges(string $before, string $after, Schema\NativeTypesPolicy $policy, ?string $reason): void
    {
        $config = new Schema\Signatures();
        $config->nativeTypes = $policy;
        [$class, $old] = self::method($before);
        [, $new] = self::method($after);

        Assert::same((new SignatureGate($config))->reject($old, $new, null, null, $class, []), $reason);
    }

    public function publicApiFlagsAllowOverridableMethods(): void
    {
        [$class, $old] = self::method('public function m($a) {}');
        [, $new] = self::method('public function m(int $a) {}');
        $config = new Schema\Signatures();

        Assert::same((new SignatureGate($config, allowPublic: true))->reject($old, $new, null, null, $class, []), null);
        $config->publicApi = true;
        Assert::same((new SignatureGate($config))->reject($old, $new, null, null, $class, []), null);
    }

    public function restrictionsAndDocblocks(): void
    {
        [$class, $old] = self::method('private function m($a) {}');
        [, $new] = self::method('private function m(int $a) {}');
        $config = new Schema\Signatures();
        $gate = new SignatureGate($config);

        Assert::same($gate->reject($old, $new, null, null, $class, [Restriction::Signature]), 'changes native types of a function with a fixed signature');
        Assert::same($gate->reject($old, $old, '/** @param mixed $a */', '/** @param int $a */', $class, []), null);
        Assert::same($gate->reject($old, $old, '/** a */', '/** b */', $class, [Restriction::Docblock]), 'changes the docblock');
        $config->phpdoc = false;
        Assert::same((new SignatureGate($config))->reject($old, $old, '/** a */', '/** b */', $class, []), 'changes the docblock');
    }

    /**
     * @return array{Stmt\Class_, Stmt\ClassMethod}
     */
    private static function method(string $code): array
    {
        $class = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php class C { {$code} }")[0] ?? null;
        Assert::instanceOf($class, Stmt\Class_::class);
        /** @var Stmt\Class_ $class */
        $method = $class->getMethods()[0];

        return [$class, $method];
    }
}
