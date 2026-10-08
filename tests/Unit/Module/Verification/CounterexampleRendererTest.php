<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification;

use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Project\Project;
use Opmin\Module\Tests\CoverageMap;
use Opmin\Module\Tests\TestResult;
use Opmin\Module\Tests\TestRunnerAdapter;
use Opmin\Module\Verification\Compare\Difference;
use Opmin\Module\Verification\Counterexample;
use Opmin\Module\Verification\CounterexampleRenderer;
use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Input\Recipes;
use Opmin\Module\Verification\Target\Target;
use PhpParser\Modifiers;
use PhpParser\Node\Stmt;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * What the renderer hands to the runner adapter: setup lines, the call, the expected literal.
 */
#[Test]
#[Covers(CounterexampleRenderer::class)]
#[Covers(Input::class)]
final class CounterexampleRendererTest
{
    /** @var array<string, mixed> Arguments of the last `counterexampleTest()` call. */
    private array $rendered = [];

    public function scalarsBecomeLiterals(): void
    {
        $this->render(self::function(), [
            Recipes::null(),
            Recipes::bool(true),
            Recipes::bool(false),
            Recipes::int(-7),
            Recipes::int(\PHP_INT_MIN),
            Recipes::float(1.5),
            Recipes::float(3.0),
            Recipes::float(1.0E+25),
            ['type' => 'float', 'value' => '4'],
            Recipes::float(\NAN),
            Recipes::float(\INF),
            Recipes::float(-\INF),
            ['type' => 'int'],
        ]);

        Assert::same($this->rendered['setup'], [
            '$arg0 = null;',
            '$arg1 = true;',
            '$arg2 = false;',
            '$arg3 = -7;',
            '$arg4 = \PHP_INT_MIN;',
            '$arg5 = 1.5;',
            '$arg6 = 3.0;',
            '$arg7 = 1.0E+25;',
            '$arg8 = 4.0;',
            '$arg9 = \NAN;',
            '$arg10 = \INF;',
            '$arg11 = -\INF;',
            '$arg12 = 0;',
        ]);
        Assert::same($this->rendered['call'], '\App\f($arg0, $arg1, $arg2, $arg3, $arg4, $arg5, $arg6, $arg7, $arg8, $arg9, $arg10, $arg11, $arg12)');
    }

    public function stringsAreEscapedOnlyWhenTheyMustBe(): void
    {
        $this->render(self::function(), [
            Recipes::string("it's"),
            Recipes::string('Ünïcödé'),
            Recipes::string("a\tb\"\$\\"),
            Recipes::string("\xff\x7f~"),
        ]);

        Assert::same($this->rendered['setup'], [
            "\$arg0 = 'it\\'s';",
            "\$arg1 = 'Ünïcödé';",
            '$arg2 = "a\x09b\"\$\\\\";',
            '$arg3 = "\xff\x7f~";',
        ]);
    }

    public function arraysEnumsAndObjectsAreBuiltInTheSetup(): void
    {
        $this->render(self::function(), [
            Recipes::array([[Recipes::string('k'), Recipes::list([Recipes::int(1)])], [Recipes::int(3), Recipes::null()]]),
            ['type' => 'enum', 'class' => '\App\Suit', 'case' => 'Hearts'],
            ['type' => 'object', 'id' => 1, 'class' => 'App\Money', 'via' => 'ctor', 'args' => [Recipes::int(5)]],
            ['type' => 'ref', 'id' => 1],
            ['type' => 'ref', 'id' => 9],
            ['type' => 'object', 'class' => 'App\Cart', 'via' => 'props', 'props' => ['items' => Recipes::list([]), 'App\Base::id' => Recipes::int(2)]],
            ['type' => 'array'],
        ]);

        Assert::same($this->rendered['setup'], [
            "\$arg0 = ['k' => [0 => 1], 3 => null];",
            '$arg1 = \App\Suit::Hearts;',
            '$object1 = new \App\Money(5);',
            '$arg2 = $object1;',
            '$arg3 = $object1;',
            '$arg4 = null;',
            "\$arg5 = (static function () {\n"
                . "    \$object = (new \\ReflectionClass(\\App\\Cart::class))->newInstanceWithoutConstructor();\n"
                . "    \\Closure::bind(fn() => \$this->items = [], \$object, \\App\\Cart::class)();\n"
                . "    \\Closure::bind(fn() => \$this->id = 2, \$object, \\App\\Base::class)();\n"
                . "    return \$object;\n"
                . '})();',
            '$arg6 = [];',
        ]);
    }

    public function descriptionCarriesTheDifferenceAndTheInputAsJson(): void
    {
        $this->render(self::function(), [Recipes::string('a/ж'), Recipes::float(2.0)], strict: true);

        Assert::same(
            $this->rendered['description'],
            "opmin: the change of App\\f behaves differently on this input.\n"
            . "Difference: calls[0].value: int 1 → int 2\n"
            . 'Input: {"args":[{"type":"string","value":"a/ж"},{"type":"float","value":"2.0"}],"this":null,"uses":[],"strict":true}',
        );
        Assert::same($this->rendered['strict'], true);
        Assert::same($this->rendered['name'], 'OpminTest');
    }

    public function returnedPlainValuesAreExpected(): void
    {
        $cases = [
            'null' => [Recipes::null(), 'null'],
            'true' => [Recipes::bool(true), 'true'],
            'false' => [Recipes::bool(false), 'false'],
            'int' => [Recipes::int(\PHP_INT_MIN), '\PHP_INT_MIN'],
            'float' => [Recipes::float(2.0), '2.0'],
            'infinity' => [Recipes::float(-\INF), '-\INF'],
            'nan' => [Recipes::float(\NAN), null],
            'string' => [Recipes::string("x\n"), '"x\x0a"'],
            'enum' => [['type' => 'enum', 'class' => 'App\Suit', 'case' => 'Clubs'], '\App\Suit::Clubs'],
            'array' => [Recipes::array([[Recipes::string('a'), Recipes::list([Recipes::bool(true)])]]), "['a' => [0 => true]]"],
            'array with an object' => [Recipes::list([['type' => 'object', 'class' => 'X']]), null],
            'array with an object key' => [Recipes::array([[['type' => 'object'], Recipes::int(1)]]), null],
            'empty array' => [['type' => 'array'], '[]'],
            'object' => [['type' => 'object', 'class' => 'X'], null],
            'not a recipe' => ['oops', null],
        ];

        foreach ($cases as $label => [$value, $expected]) {
            $this->render(self::function(), [], original: ['calls' => [['status' => 'returned', 'value' => $value]]]);

            Assert::same($this->rendered['expected'], $expected, $label);
            Assert::same($this->rendered['exception'], null, $label);
        }
    }

    public function thrownExceptionsAndOutputAreExpected(): void
    {
        $this->render(self::function(), [], original: ['calls' => [[
            'status' => 'threw',
            'exception' => ['class' => 'DomainException', 'message' => 'bad'],
            'output' => Recipes::string('printed'),
        ]]]);

        Assert::same($this->rendered['exception'], ['class' => 'DomainException', 'message' => 'bad']);
        Assert::same($this->rendered['expected'], null);
        Assert::same($this->rendered['output'], 'printed');

        $this->render(self::function(), [], original: ['calls' => [['status' => 'threw', 'exception' => []]]]);
        Assert::same($this->rendered['exception'], ['class' => 'Throwable', 'message' => '']);
        Assert::same($this->rendered['output'], null);

        $this->render(self::function(), [], original: ['calls' => [['status' => 'threw', 'output' => Recipes::string('')]]]);
        Assert::same($this->rendered['exception'], null);
        Assert::same($this->rendered['output'], null);

        $this->render(self::function(), [], original: ['calls' => [['status' => 'returned', 'value' => Recipes::int(1), 'exception' => ['class' => 'E']]]]);
        Assert::same($this->rendered['exception'], null);
        Assert::same($this->rendered['expected'], '1');
    }

    public function methodsAreCalledTheWayTheirVisibilityAllows(): void
    {
        $receiver = ['type' => 'object', 'id' => 1, 'class' => 'App\Svc', 'via' => 'ctor', 'args' => []];
        $cases = [
            'constructor' => [self::method('__CONSTRUCT', Modifiers::PUBLIC), null, 'new \App\Svc($arg0)'],
            'public static' => [self::method('make', Modifiers::PUBLIC | Modifiers::STATIC), null, '\App\Svc::make($arg0)'],
            'private static' => [self::method('hide', Modifiers::PRIVATE | Modifiers::STATIC), null, '\Closure::bind(static fn() => static::hide($arg0), null, \App\Svc::class)()'],
            'public' => [self::method('run', Modifiers::PUBLIC), $receiver, '$subject->run($arg0)'],
            'private' => [self::method('hidden', Modifiers::PRIVATE), $receiver, '(fn() => $this->hidden($arg0))->call($subject)'],
        ];

        foreach ($cases as $label => [$target, $subject, $call]) {
            $this->render($target, [Recipes::int(1)], receiver: $subject);

            Assert::same($this->rendered['call'], $call, $label);
            Assert::same(\end($this->rendered['setup']), $subject === null ? '$arg0 = 1;' : '$subject = $object1;', $label);
        }
    }

    public function inputsThatPhpCannotSpellBecomeIncompleteTests(): void
    {
        $this->render(self::function(), [Recipes::int(1), ['type' => 'callable', 'returns' => []]], original: ['calls' => [['status' => 'returned', 'value' => Recipes::int(1)]]]);

        Assert::same($this->rendered['setup'], []);
        Assert::same($this->rendered['call'], 'null');
        Assert::same($this->rendered['expected'], null);
        Assert::string((string) $this->rendered['description'])->endsWith("\nThe input cannot be written as plain PHP: reproduce it with the JSON above.");

        $this->render(self::target(['kind' => 'closure'], new Stmt\Function_('f')), [Recipes::int(1)]);
        Assert::same($this->rendered['call'], 'null');

        # A fresh render forgets the unsupported input of the previous one.
        $this->render(self::function(), [Recipes::int(1)]);
        Assert::same($this->rendered['call'], '\App\f($arg0)');
        Assert::same($this->rendered['setup'], ['$arg0 = 1;']);
    }

    public function inputSurvivesItsArrayForm(): void
    {
        $input = new Input([Recipes::int(1)], ['type' => 'object'], ['k' => Recipes::null()], true);

        Assert::same($input->toArray(), ['args' => [Recipes::int(1)], 'this' => ['type' => 'object'], 'uses' => ['k' => Recipes::null()], 'strict' => true]);
        Assert::same(Input::fromArray($input->toArray())->toArray(), $input->toArray());
        Assert::same(Input::fromArray([])->toArray(), ['args' => [], 'this' => null, 'uses' => [], 'strict' => false]);
    }

    private static function function(): Target
    {
        return self::target(['kind' => 'function', 'name' => 'App\f'], new Stmt\Function_('f'));
    }

    private static function method(string $name, int $flags): Target
    {
        return self::target(['kind' => 'method', 'class' => '\App\Svc', 'name' => $name], new Stmt\ClassMethod($name, ['flags' => $flags]));
    }

    /**
     * @param array<string, mixed> $call
     */
    private static function target(array $call, Stmt\Function_|Stmt\ClassMethod $node): Target
    {
        return new Target('App\f', UnitKind::Function, $call, 'App', $node, null, null, []);
    }

    /**
     * @return TestRunnerAdapter&object{rendered: array<string, mixed>}
     */
    private static function adapter(): TestRunnerAdapter
    {
        return new class implements TestRunnerAdapter {
            /** @var array<string, mixed> */
            public array $rendered = [];

            public static function detect(Project $project): bool
            {
                return false;
            }

            public function name(): string
            {
                return 'fake';
            }

            public function runAll(): TestResult
            {
                throw new \LogicException('Not run.');
            }

            public function runFiltered(array $testIds): TestResult
            {
                throw new \LogicException('Not run.');
            }

            public function collectCoverageMap(): ?CoverageMap
            {
                return null;
            }

            public function counterexampleTest(string $name, string $description, array $setup, string $call, ?string $expected, ?array $exception, ?string $output, bool $strict = false): string
            {
                $this->rendered = \compact('name', 'description', 'setup', 'call', 'expected', 'exception', 'output', 'strict');

                return '';
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $args
     * @param array<string, mixed>|null $receiver
     * @param array<string, mixed> $original
     */
    private function render(Target $target, array $args, ?array $receiver = null, array $original = [], bool $strict = false): void
    {
        $counterexample = new Counterexample(
            new Input($args, $receiver, [], $strict),
            new Difference('calls[0].value', 'int 1', 'int 2'),
            $original,
            [],
            1,
        );
        $adapter = self::adapter();
        (new CounterexampleRenderer())->render($target, $counterexample, $adapter, 'OpminTest');
        $this->rendered = $adapter->rendered;
    }
}
