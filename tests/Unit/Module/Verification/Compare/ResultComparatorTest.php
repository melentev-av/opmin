<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Compare;

use Opmin\Module\Config\Schema\WarningsPolicy;
use Opmin\Module\Verification\Compare\ComparisonPolicy;
use Opmin\Module\Verification\Compare\Difference;
use Opmin\Module\Verification\Compare\ResultComparator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ResultComparator::class)]
#[Covers(ComparisonPolicy::class)]
#[Covers(Difference::class)]
final class ResultComparatorTest
{
    /**
     * Pairs of results that differ in behavior: [original, changed, path of the difference].
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function differences(): iterable
    {
        yield 'int vs float' => [self::returned(self::int(1)), self::returned(self::float('1.0')), 'calls[0].value.type'];
        yield '-0.0 vs 0.0' => [self::returned(self::float('-0.0')), self::returned(self::float('0.0')), 'calls[0].value'];
        yield 'NAN vs a number' => [self::returned(self::float('NAN')), self::returned(self::float('1.0')), 'calls[0].value'];
        yield 'INF vs -INF' => [self::returned(self::float('INF')), self::returned(self::float('-INF')), 'calls[0].value'];
        yield 'float beyond tolerance' => [self::returned(self::float('1.0')), self::returned(self::float('1.0001')), 'calls[0].value'];
        yield 'string' => [self::returned(self::string('a')), self::returned(self::string('b')), 'calls[0].value'];
        yield 'array key order' => [
            self::returned(self::array([[self::string('a'), self::int(1)], [self::string('b'), self::int(2)]])),
            self::returned(self::array([[self::string('b'), self::int(2)], [self::string('a'), self::int(1)]])),
            'calls[0].value.items[0][0]',
        ];
        yield 'int key vs string key' => [
            self::returned(self::array([[self::int(1), self::null()]])),
            self::returned(self::array([[self::string('1'), self::null()]])),
            'calls[0].value.items[0][0].type',
        ];
        yield 'object identity' => [
            self::returned(self::array([[self::int(0), self::object(1)], [self::int(1), self::object(1)]])),
            self::returned(self::array([[self::int(0), self::object(1)], [self::int(1), self::object(2)]])),
            'calls[0].value.items[1][1].id',
        ];
        yield 'object property' => [self::returned(self::object(1, [['n', self::int(1)]])), self::returned(self::object(1, [['n', self::int(2)]])), 'calls[0].value.props[0][1].value'];
        yield 'returned vs threw' => [self::returned(self::null()), self::threw('RuntimeException', 'x'), 'calls[0].status'];
        yield 'exception class' => [self::threw('RuntimeException', 'x'), self::threw('LogicException', 'x'), 'calls[0].exception.class'];
        yield 'exception message' => [self::threw('RuntimeException', 'x'), self::threw('RuntimeException', 'y'), 'calls[0].exception.message'];
        yield 'exception code' => [self::threw('RuntimeException', 'x', 1), self::threw('RuntimeException', 'x', 2), 'calls[0].exception.code'];
        yield 'previous exception' => [
            self::threw('RuntimeException', 'x', previous: ['class' => 'LogicException', 'message' => 'a', 'code' => 0, 'previous' => null]),
            self::threw('RuntimeException', 'x', previous: null),
            'calls[0].exception.previous',
        ];
        yield 'output' => [self::returned(self::null(), output: 'a'), self::returned(self::null(), output: 'b'), 'calls[0].output'];
        yield 'warning removed' => [self::returned(self::null(), errors: [self::error('Undefined array key "x"')]), self::returned(self::null()), 'calls[0].errors'];
        yield 'warning added' => [self::returned(self::null()), self::returned(self::null(), errors: [self::error('Undefined array key "x"')]), 'calls[0].errors'];
        yield 'deprecation added' => [self::returned(self::null()), self::returned(self::null(), errors: [self::error('old', 'E_DEPRECATED')]), 'calls[0].errors'];
        yield 'warning level' => [self::returned(self::null(), errors: [self::error('x')]), self::returned(self::null(), errors: [self::error('x', 'E_NOTICE')]), 'calls[0].errors'];
        yield 'by-ref argument' => [self::with('args', [self::int(1)]), self::with('args', [self::int(2)]), 'args[0].value'];
        yield 'this after the call' => [self::with('this', self::object(1, [['n', self::int(1)]])), self::with('this', self::object(1, [['n', self::int(5)]])), 'this.props[0][1].value'];
        yield 'mock call order' => [
            self::with('mocks', [['mock' => 1, 'method' => 'a', 'args' => []], ['mock' => 1, 'method' => 'b', 'args' => []]]),
            self::with('mocks', [['mock' => 1, 'method' => 'b', 'args' => []], ['mock' => 1, 'method' => 'a', 'args' => []]]),
            'mocks[0].method',
        ];
        yield 'mock call arguments' => [
            self::with('mocks', [['mock' => 1, 'method' => 'a', 'args' => [self::int(1)]]]),
            self::with('mocks', [['mock' => 1, 'method' => 'a', 'args' => [self::int(2)]]]),
            'mocks[0].args[0].value',
        ];
        yield 'global changed' => [self::with('globals', []), self::with('globals', ['x' => self::int(1)]), 'globals'];
        yield 'static changed' => [self::with('statics', ['A::$n' => self::int(1)]), self::with('statics', ['A::$n' => self::int(2)]), 'statics.A::$n.value'];
        yield 'fatal vs returned' => [['status' => 'fatal', 'calls' => [['status' => 'fatal', 'message' => 'x']]], self::returned(self::null()), 'status'];
        yield 'second call of a chain' => [
            ['status' => 'done', 'calls' => [self::call(self::int(1)), self::call(self::int(2))]],
            ['status' => 'done', 'calls' => [self::call(self::int(1)), self::call(self::int(1))]],
            'calls[1].value.value',
        ];
    }

    /**
     * Pairs that are the same behavior.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function equivalents(): iterable
    {
        yield 'float within tolerance' => [self::returned(self::float('0.30000000000000004')), self::returned(self::float('0.3'))];
        yield 'NAN equals NAN' => [self::returned(self::float('NAN')), self::returned(self::float('NAN'))];
        yield 'base64 and plain string of the same bytes' => [self::returned(self::string('abc')), self::returned(['type' => 'string', 'base64' => \base64_encode('abc')])];
        yield 'suppressed warning dropped' => [self::returned(self::null(), errors: [self::error('x', suppressed: true)]), self::returned(self::null())];
        yield 'warning lines are not compared' => [self::returned(self::null(), errors: [self::error('x', line: 3)]), self::returned(self::null(), errors: [self::error('x', line: 9)])];
        yield 'caller line in a TypeError' => [
            self::threw('TypeError', 'f(): Argument #1 ($x) must be of type int, string given, called in /a.php on line 3'),
            self::threw('TypeError', 'f(): Argument #1 ($x) must be of type int, string given, called in /a.php on line 7'),
        ];
        yield 'coverage is not behavior' => [self::with('probes', [1, 2]), self::with('probes', [1])];
        yield 'field order of a description' => [self::returned(['value' => 1, 'type' => 'int']), self::returned(['type' => 'int', 'value' => 1])];
    }

    /**
     * @return array<string, mixed>
     */
    public static function returned(array $value, string $output = '', array $errors = []): array
    {
        return ['status' => 'done', 'calls' => [self::call($value, $output, $errors)], 'args' => [], 'this' => null, 'mocks' => [], 'globals' => [], 'statics' => []];
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $changed
     */
    #[DataProvider('differences')]
    public function findsDifference(array $original, array $changed, string $path): void
    {
        $difference = (new ResultComparator())->compare($original, $changed);

        Assert::same($difference?->path, $path);
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $changed
     */
    #[DataProvider('equivalents')]
    public function acceptsEquivalent(array $original, array $changed): void
    {
        Assert::null((new ResultComparator())->compare($original, $changed));
    }

    public function zeroToleranceComparesFloatsBitwise(): void
    {
        $comparator = new ResultComparator(new ComparisonPolicy(floatTolerance: 0.0));

        Assert::same($comparator->compare(self::returned(self::float('0.30000000000000004')), self::returned(self::float('0.3')))?->path, 'calls[0].value');
    }

    public function allowRemovalAcceptsDroppedWarningsOnly(): void
    {
        $comparator = new ResultComparator(new ComparisonPolicy(WarningsPolicy::AllowRemoval));
        $two = self::returned(self::null(), errors: [self::error('a'), self::error('b')]);

        Assert::null($comparator->compare($two, self::returned(self::null(), errors: [self::error('b')])));
        Assert::null($comparator->compare($two, self::returned(self::null())));
        Assert::notNull($comparator->compare($two, self::returned(self::null(), errors: [self::error('b'), self::error('a')])));
        Assert::notNull($comparator->compare($two, self::returned(self::null(), errors: [self::error('a'), self::error('b'), self::error('c', 'E_DEPRECATED')])));
    }

    public function differenceReadsAsBeforeAndAfter(): void
    {
        $difference = (new ResultComparator())->compare(self::returned(self::int(1)), self::returned(self::int(2)));

        Assert::same((string) $difference, 'calls[0].value.value: 1 → 2');
    }

    /**
     * The result of a call returning null, with one field replaced.
     *
     * @return array<string, mixed>
     */
    private static function with(string $key, mixed $value): array
    {
        return [$key => $value] + self::returned(self::null());
    }

    /**
     * @param array<string, mixed>|null $previous
     * @return array<string, mixed>
     */
    private static function threw(string $class, string $message, int $code = 0, ?array $previous = null): array
    {
        return ['status' => 'done', 'calls' => [[
            'status' => 'threw',
            'exception' => ['class' => $class, 'message' => $message, 'code' => $code, 'previous' => $previous],
            'output' => self::string(''),
            'errors' => [],
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function call(array $value, string $output = '', array $errors = []): array
    {
        return ['status' => 'returned', 'value' => $value, 'output' => self::string($output), 'errors' => $errors];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(string $message, string $level = 'E_WARNING', bool $suppressed = false, ?int $line = null): array
    {
        return ['level' => $level, 'message' => $message, 'line' => $line, 'suppressed' => $suppressed];
    }

    private static function int(int $value): array
    {
        return ['type' => 'int', 'value' => $value];
    }

    private static function float(string $value): array
    {
        return ['type' => 'float', 'value' => $value];
    }

    private static function string(string $value): array
    {
        return ['type' => 'string', 'value' => $value];
    }

    private static function null(): array
    {
        return ['type' => 'null'];
    }

    /**
     * @param list<array{array<string, mixed>, array<string, mixed>}> $items
     */
    private static function array(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @param list<array{string, array<string, mixed>}> $props
     */
    private static function object(int $id, array $props = []): array
    {
        return ['type' => 'object', 'class' => 'App\A', 'id' => $id, 'props' => $props];
    }
}
