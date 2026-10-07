<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Analysis;

use Opmin\Module\Analysis\IgnoreMarks;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Every form of "do not touch" (brief, «Игнорирование кода»): the attribute (also imported under
 * another name), docblock and plain comments, per rule and for every rule, and the config patterns.
 */
#[Test]
#[Covers(IgnoreMarks::class)]
final class IgnoreMarksTest
{
    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function marks(): iterable
    {
        $fn = static fn(string $mark): string => "<?php\nnamespace App;\n{$mark}\nfunction f() {}\n";

        yield 'no mark' => [$fn(''), [], false];
        yield 'attribute' => [$fn('#[\Opmin\Ignore]'), ['fqn'], true];
        yield 'attribute, no rule asked' => [$fn('#[\Opmin\Ignore]'), [], true];
        yield 'attribute imported' => ["<?php\nnamespace App;\nuse Opmin\\Ignore;\n#[Ignore]\nfunction f() {}\n", ['fqn'], true];
        yield 'attribute imported under an alias' => ["<?php\nnamespace App;\nuse Opmin\\Ignore as Keep;\n#[Keep]\nfunction f() {}\n", ['fqn'], true];
        yield 'attribute of another namespace' => [$fn('#[Ignore]'), ['fqn'], false];
        yield 'attribute, the rule listed' => [$fn("#[\\Opmin\\Ignore(rules: ['fqn', 'llm'])]"), ['llm'], true];
        yield 'attribute, another rule listed' => [$fn("#[\\Opmin\\Ignore(rules: ['fqn'])]"), ['llm'], false];
        yield 'attribute, positional rules' => [$fn("#[\\Opmin\\Ignore(['FQN'])]"), ['fqn'], true];
        yield 'attribute, empty rules: every rule' => [$fn('#[\Opmin\Ignore(rules: [])]'), ['fqn'], true];
        yield 'attribute among others' => [$fn('#[\Deprecated, \Opmin\Ignore]'), ['fqn'], true];
        yield 'docblock' => [$fn("/**\n * Sums.\n * @opmin-ignore\n */"), ['fqn'], true];
        yield 'docblock, the rule listed' => [$fn('/** @opmin-ignore fqn, llm */'), ['llm'], true];
        yield 'docblock, another rule listed' => [$fn('/** @opmin-ignore fqn,isset */'), ['llm'], false];
        yield 'docblock, short class name of a rule' => [$fn('/** @opmin-ignore SimplifyIfReturnBoolRector */'), ['simplifyifreturnboolrector'], true];
        yield 'line comment' => [$fn('// @opmin-ignore'), ['fqn'], true];
        yield 'line comment, the rule listed' => [$fn('// @opmin-ignore fqn'), ['fqn'], true];
        yield 'line comment, another rule listed' => [$fn('// @opmin-ignore llm'), ['fqn'], false];
        yield 'hash comment' => [$fn('# @opmin-ignore'), ['fqn'], true];
        yield 'block comment' => [$fn('/* @opmin-ignore */'), ['fqn'], true];
        yield 'line comment before the docblock' => [$fn("// @opmin-ignore llm\n/** Sums. */"), ['llm'], true];
        yield 'two comments, the second one matches' => [$fn("// @opmin-ignore fqn\n// @opmin-ignore llm"), ['llm'], true];
        yield 'similar word' => [$fn('// @opmin-ignored'), ['fqn'], false];
        yield 'comment of an earlier statement' => ["<?php\nnamespace App;\n// @opmin-ignore\nconst A = 1;\nfunction f() {}\n", ['fqn'], false];
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function patterns(): iterable
    {
        yield 'exact method' => [['App\Foo\Bar::hotPath'], 'App\Foo\Bar::hotPath', true];
        yield 'leading backslash' => [['\App\Foo\Bar::hotPath'], 'App\Foo\Bar::hotPath', true];
        yield 'escaped backslashes' => [['App\\\\Foo\\\\Bar::hotPath'], 'App\Foo\Bar::hotPath', true];
        yield 'case-insensitive' => [['app\foo\bar::HOTPATH'], 'App\Foo\Bar::hotPath', true];
        yield 'namespace wildcard' => [['App\Utils\*'], 'App\Utils\Str::slug', true];
        yield 'method wildcard' => [['App\Foo\Bar::hot*'], 'App\Foo\Bar::hotPath', true];
        yield 'another method' => [['App\Foo\Bar::hotPath'], 'App\Foo\Bar::coldPath', false];
        yield 'prefix only' => [['App\Foo\Bar'], 'App\Foo\Bar::hotPath', false];
        yield 'dot is not a wildcard' => [['App.Foo'], 'AppxFoo', false];
        yield 'closure of an ignored method' => [['App\Foo\Bar::hotPath*'], 'App\Foo\Bar::hotPath::{closure#1}', true];
        yield 'no patterns' => [[], 'App\Foo\Bar::hotPath', false];
    }

    #[DataProvider('marks')]
    public function functionMarks(string $code, array $rules, bool $expected): void
    {
        $function = self::first($code, Node\Stmt\Function_::class);

        Assert::same(IgnoreMarks::ignored($function, $rules), $expected);
    }

    public function methodAndClassMarks(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;

            // @opmin-ignore llm
            final class Svc
            {
                // @opmin-ignore
                public function kept(): int { return 1; }

                #[\Opmin\Ignore(rules: ['fqn'])]
                public function noFqn(): int { return 1; }

                public function free(): int { return 1; }
            }
            PHP;
        $class = self::first($code, Node\Stmt\Class_::class);
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[$method->name->toString()] = $method;
        }

        Assert::true(IgnoreMarks::ignored($class, ['llm']));
        Assert::false(IgnoreMarks::ignored($class, ['fqn']));
        Assert::true(IgnoreMarks::ignored($methods['kept'], ['fqn']));
        Assert::true(IgnoreMarks::ignored($methods['noFqn'], ['fqn']));
        Assert::false(IgnoreMarks::ignored($methods['noFqn'], ['llm']));
        Assert::false(IgnoreMarks::ignored($methods['free'], ['fqn']));
    }

    public function closureMarks(): void
    {
        $code = "<?php\n\$a = #[\\Opmin\\Ignore] static fn(int \$x): int => \$x + 1;\n\$b = static fn(int \$x): int => \$x;\n";
        $closures = (new NodeFinder())->findInstanceOf(self::parse($code), Node\Expr\ArrowFunction::class);

        Assert::true(IgnoreMarks::ignored($closures[0], ['fqn']));
        Assert::false(IgnoreMarks::ignored($closures[1], ['fqn']));
    }

    #[DataProvider('patterns')]
    public function configPatterns(array $patterns, string $key, bool $expected): void
    {
        Assert::same(IgnoreMarks::byConfig($patterns, $key), $expected);
    }

    /**
     * @template T of Node
     * @param class-string<T> $class
     * @return T
     */
    private static function first(string $code, string $class): Node
    {
        $node = (new NodeFinder())->findFirstInstanceOf(self::parse($code), $class);
        Assert::instanceOf($node, $class);

        return $node;
    }

    /**
     * @return list<Node>
     */
    private static function parse(string $code): array
    {
        $stmts = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser(new NameResolver());

        return \array_values($traverser->traverse($stmts));
    }
}
