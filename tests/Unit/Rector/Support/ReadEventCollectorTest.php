<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Rector\Support;

use Opmin\Rector\Support\ReadEvent;
use Opmin\Rector\Support\ReadEventCollector;
use Opmin\Rector\Support\ReadSpec;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The cause every event carries for the decline log of the extract rules: what kind of call, write
 * or opaque construct stops a group of reads.
 */
#[Test]
#[Covers(ReadEventCollector::class)]
final class ReadEventCollectorTest
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function causes(): iterable
    {
        yield 'own method' => ['$this->m();', ['impure method_call_this']];
        yield 'method of another object' => ['$this->logger->info();', ['impure method_call']];
        yield 'nullsafe method' => ['$x?->m();', ['impure method_call']];
        yield 'own static method' => ['self::m(); parent::m();', ['impure static_call_own', 'impure static_call_own']];
        yield 'static method of another class' => ['Foo::m();', ['impure static_call']];
        yield 'new' => ['new Foo();', ['impure new']];
        yield 'named function' => ['\strlen($s);', ['base_write by_ref_arg', 'impure function:strlen']];
        yield 'dynamic function' => ['$f();', ['impure function_dynamic']];
        yield 'write' => ['$this->p = 1;', ['write write']];
        yield 'argument maybe by reference' => ['foo($this->p);', ['write by_ref_arg', 'impure function:foo']];
        yield 'base variable written' => ['$x = 1;', ['base_write base_write', 'impure destructor']];
        yield 'reference' => ['$r = &$this->p;', ['base_write base_write', 'write write', 'opaque reference']];
        yield 'output' => ['echo 1;', ['impure output']];
        yield 'static property' => ['Foo::$p;', ['impure static_property']];
        yield 'unknown statement' => ['global $g;', ['opaque opaque:Stmt_Global']];
    }

    /**
     * @param list<string> $expected `kind cause` of every event that is not a read of `$this->p`.
     */
    #[DataProvider('causes')]
    public function eventCarriesItsCause(string $code, array $expected): void
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php\n" . $code) ?? [];
        $collector = new ReadEventCollector(
            new class implements ReadSpec {
                public function match(Expr $expr): ?array
                {
                    return $expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this'
                        && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'p' ? ['$this->p', 'this'] : null;
                }

                public function safe(Expr $read): bool
                {
                    return true;
                }

                public function stable(Expr $read): bool
                {
                    return false;
                }

                public function name(Expr $read): string
                {
                    return 'p';
                }
            },
            static fn(Expr $e): bool => false,
            static fn(Expr\PropertyFetch $e): bool => true,
            static fn(Expr\CallLike $call, int $position): ?bool => null,
            static fn(Expr\FuncCall $call): bool => false,
        );

        /** @var list<Stmt> $stmts */
        $events = $collector->collect($stmts);

        Assert::same(\array_map(static fn(ReadEvent $e): string => $e->kind . ' ' . ($e->cause ?? '-'), $events), $expected);
    }
}
