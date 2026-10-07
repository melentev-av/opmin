<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input\LiteralPool;
use Opmin\Module\Verification\Input\Recipes;
use Opmin\Module\Verification\Input\TypeSpec;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(LiteralPool::class)]
final class LiteralPoolTest
{
    public function collectsLiteralsAndTheirNeighbours(): void
    {
        $pool = self::pool(<<<'PHP'
            function f($a) {
                if ($a === 'go' || $a === 7 || $a === -3 || $a === 1.5 || $a === -2.5) { return $a['k'] + $a[4]; }
                $map = ['key' => 1, 9 => 2];
                $f = function () { return 'closure-only'; };
                $o = new class { public function m() { return 'class-only'; } };
                return 'A';
            }
            PHP);

        Assert::same($pool->ints, [7, 6, 8, -3, -4, -2, 3, 2, 4, 5, 1, 0, 9, 10]);
        Assert::same($pool->floats, [1.5, -2.5, 2.5]);
        Assert::same($pool->strings, ['go', 'gox', 'xgo', 'g', 'GO', 'k', 'kx', 'xk', 'K', 'key', 'keyx', 'xkey', 'ke', 'KEY', 'A', 'Ax', 'xA']);
        Assert::same($pool->keys, ['k', 4, 'key', 9]);
        Assert::false($pool->isEmpty());
    }

    public function edgeStringsAndNumbers(): void
    {
        $long = \str_repeat('y', 65);
        $pool = self::pool("function f() { return [\$a ?? '', \\PHP_INT_MAX, '{$long}', 'Z']; }");
        $max = self::pool('function f() { return 9223372036854775807; }');
        $min = (new LiteralPool())->addInts([\PHP_INT_MIN]);

        Assert::same($pool->strings, ['', $long, 'Z', 'Zx', 'xZ']);
        Assert::same($max->ints, [\PHP_INT_MAX, \PHP_INT_MAX - 1]);
        Assert::same($min->ints, [\PHP_INT_MIN, \PHP_INT_MIN + 1]);
        Assert::true(self::pool('function f() { return 1; }')->ints !== []);
        Assert::true((new LiteralPool())->isEmpty());
    }

    public function arrowFunctionsAndSeveralVersions(): void
    {
        $code = '<?php $f = fn($x) => $x === "arrow"; function g() { return "other"; }';
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $arrow = (new NodeFinder())->findFirstInstanceOf($nodes, Node\Expr\ArrowFunction::class);
        $function = (new NodeFinder())->findFirstInstanceOf($nodes, Node\Stmt\Function_::class);
        \assert($arrow instanceof Node\Expr\ArrowFunction && $function instanceof Node\Stmt\Function_);

        $pool = LiteralPool::collect($arrow, $function);

        Assert::same(\array_slice($pool->strings, 0, 1), ['arrow']);
        Assert::true(\in_array('other', $pool->strings, true));
    }

    public function recipesFitTheType(): void
    {
        $pool = self::pool("function f(\$a) { return [\$a === 'x', \$a === '12', \$a === 3, \$a === 2.5]; }");

        Assert::same($pool->recipesFor(TypeSpec::of(TypeSpec::STRING)), \array_map(Recipes::string(...), ['x', 'xx', 'X', '12', '12x', 'x12', '1', '3', '2', '4']));
        Assert::same($pool->recipesFor(TypeSpec::of(TypeSpec::INT)), [Recipes::int(3), Recipes::int(2), Recipes::int(4), Recipes::int(12), Recipes::int(1)]);
        Assert::same($pool->recipesFor(TypeSpec::of(TypeSpec::FLOAT)), [Recipes::float(2.5), Recipes::float(3.0), Recipes::float(2.0), Recipes::float(4.0)]);
        Assert::same($pool->recipesFor(TypeSpec::of(TypeSpec::BOOL)), []);
        Assert::same(\count($pool->recipesFor(TypeSpec::mixed())), 19);
    }

    public function keyCandidatesComeFromKeysStringsAndNumbers(): void
    {
        $pool = self::pool("function f(\$a) { return \$a['id'] ?? array_key_exists('name', \$a) ?? \$a === '42' ?? str_repeat('x', 40) ?? 5; }");
        $many = self::pool('function f() { return [' . \implode(', ', \array_map(static fn(int $i): string => "'k{$i}' => {$i}", \range(1, 40))) . ']; }');

        Assert::same($pool->keyCandidates(), ['id', 'idx', 'xid', 'i', 'ID', 'name', 'namex', 'xname', 'nam', 'NAME', '42x', 'x42', 'x', 'xx', 'X', 40, 39, 41, 5, 4, 6]);
        Assert::same(\count($many->keyCandidates()), 24);
        Assert::same($many->keyCandidates()[0], 'k1');
    }

    public function poolsAreLimited(): void
    {
        $pool = self::pool('function f() { return [' . \implode(', ', \range(100, 200)) . ']; }');

        Assert::same(\count($pool->ints), 64);
        Assert::same(\count($pool->recipesFor(TypeSpec::of(TypeSpec::FLOAT))), 8);
    }

    public function boundariesOfLengthsAndCounts(): void
    {
        $s32 = \str_repeat('a', 32);
        $s33 = \str_repeat('b', 33);
        $s64 = \str_repeat('c', 64);
        $keys = self::pool("function f() { return ['{$s32}', '{$s33}']; }");
        $neighbours = self::pool("function f() { return '{$s64}'; }");
        $ints = self::pool('function f() { return [10, 20, 30, 40]; }');

        Assert::true(\in_array($s32, $keys->keyCandidates(), true));
        Assert::false(\in_array($s33, $keys->keyCandidates(), true));
        Assert::same(\count($neighbours->strings), 5);
        Assert::same($ints->keyCandidates(), [10, 9, 11, 20, 19, 21, 30, 29]);
    }

    public function duplicatesAreDroppedAndListsStayLists(): void
    {
        $pool = self::pool("function f(\$a) { return [\$a['k'], \$a['k'], 'k', 0.5, 0.5, -0.5, 'k1', 'k1']; }");
        $added = (new LiteralPool())->addInts([5, 5, 6]);

        Assert::same($pool->keys, ['k']);
        Assert::same($pool->floats, [0.5, -0.5]);
        Assert::same(\array_slice($pool->strings, 0, 5), ['k', 'kx', 'xk', 'K', 'k1']);
        Assert::same($pool->keyCandidates(), ['k', 'kx', 'xk', 'K', 'k1', 'k1x', 'xk1', 'K1']);
        Assert::same($added->ints, [5, 4, 6, 7]);
        Assert::same(self::pool('function f() { return 0.5; }')->floats, [0.5]);
    }

    public function anyKindOfLiteralMakesThePoolNonEmpty(): void
    {
        Assert::false(self::pool('function f() { return 0.5; }')->isEmpty());
        Assert::false(self::pool("function f() { return ''; }")->isEmpty());
        Assert::false(self::pool('function f($a) { return $a[$a]["k"]; }')->isEmpty());
        Assert::true(self::pool('function f($a) { return $a; }')->isEmpty());
    }

    public function onlyWholeNumbersInStringsBecomeInts(): void
    {
        $pool = self::pool("function f(\$a) { return \$a === '12abc' || \$a === '-7'; }");

        Assert::same($pool->recipesFor(TypeSpec::of(TypeSpec::INT)), [Recipes::int(-7)]);
    }

    private static function pool(string $function): LiteralPool
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php {$function}") ?? [];
        $node = (new NodeFinder())->findFirstInstanceOf($nodes, Node\Stmt\Function_::class);
        \assert($node instanceof Node\Stmt\Function_);

        return LiteralPool::collect($node);
    }
}
