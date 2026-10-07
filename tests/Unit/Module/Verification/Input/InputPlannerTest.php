<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Input;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Input\ClassInfoProvider;
use Opmin\Module\Verification\Input\Feedback;
use Opmin\Module\Verification\Input\InputPlanner;
use Opmin\Module\Verification\Input\LiteralPool;
use Opmin\Module\Verification\Input\Parameter;
use Opmin\Module\Verification\Input\Recipes;
use Opmin\Module\Verification\Input\Signature;
use Opmin\Module\Verification\Input\TypeSpec;
use Opmin\Module\Verification\Input\ValueGenerator;
use Opmin\Module\Verification\Property\Core\CoreRandom;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(InputPlanner::class)]
#[Covers(ValueGenerator::class)]
#[Covers(LiteralPool::class)]
#[Covers(Signature::class)]
final class InputPlannerTest
{
    public function planHasBoundariesLiteralsAndValuesOutsideTheType(): void
    {
        $planner = self::planner(new Signature([new Parameter('code', TypeSpec::of(TypeSpec::STRING), typed: true), new Parameter('n', TypeSpec::of(TypeSpec::INT), optional: true, typed: true)]), <<<'PHP'
            <?php function f(string $code, int $n = 3) { if ($code === 'magic' && $n > 41) { return 1; } return 0; }
            PHP);

        $plan = $planner->plan();
        $first = \array_map(static fn(Input $i): array => $i->args[0] ?? [], $plan);
        $second = \array_map(static fn(Input $i): array => $i->args[1] ?? [], $plan);

        Assert::same($plan[0]->args, [Recipes::string('')]);
        Assert::same($plan[1]->args, [Recipes::string(''), Recipes::int(0)]);
        Assert::true(\in_array(Recipes::string('magic'), $first, true));
        Assert::true(\in_array(Recipes::string('magicx'), $first, true));
        Assert::true(\in_array(Recipes::int(42), $second, true));
        Assert::true(\in_array(Recipes::int(\PHP_INT_MAX), $second, true));
        Assert::true(\in_array(Recipes::string('5'), $second, true));
        Assert::true(\in_array(Recipes::null(), $first, true));
    }

    public function interfaceBecomesAMockAndClassesAreBuilt(): void
    {
        $signature = new Signature([new Parameter('clock', new TypeSpec(TypeSpec::CLASS_, 'App\Clock')), new Parameter('money', new TypeSpec(TypeSpec::CLASS_, 'App\Money'))]);

        $plan = self::planner($signature, '<?php function f($clock, $money) { return 1; }')->plan();

        Assert::same($plan[0]->args[0], ['type' => 'mock', 'interface' => 'App\Clock', 'returns' => [], 'id' => 1]);
        Assert::same($plan[0]->args[1], ['type' => 'object', 'class' => 'App\Money', 'via' => 'ctor', 'args' => [Recipes::int(0)], 'id' => 2]);
    }

    public function randomInputsAreReproducibleBySeed(): void
    {
        $signature = new Signature([new Parameter('a', TypeSpec::mixed()), new Parameter('b', TypeSpec::of(TypeSpec::ARRAY))]);
        $draw = static function () use ($signature): array {
            $planner = self::planner($signature, '<?php function f($a, $b) { return $b["k"] ?? $a; }');
            $planner->fuzz();
            $random = new CoreRandom(new Random(7));

            return \array_map(static fn(): array => $planner->generate($random)->toArray(), \range(1, 20));
        };

        Assert::same($draw(), $draw());
    }

    public function planCoversReceiverUsesVariadicsAndStrictCallers(): void
    {
        $signature = new Signature(
            [new Parameter('a', TypeSpec::of(TypeSpec::BOOL)), new Parameter('rest', TypeSpec::of(TypeSpec::BOOL), optional: true, variadic: true)],
            [new Parameter('k', TypeSpec::of(TypeSpec::BOOL))],
            'App\\Money',
        );

        $plan = self::planner($signature, '<?php function f($a, ...$rest) { return 1; }', mixStrict: true)->plan();
        $weak = \array_values(\array_filter($plan, static fn(Input $i): bool => !$i->strict));

        Assert::same(\count($plan), 2 * \count($weak));
        Assert::same($plan[1]->toArray(), $plan[0]->withStrict(true)->toArray());
        Assert::same($weak[0]->args, [Recipes::bool(false)]);
        Assert::same($weak[0]->uses, ['k' => Recipes::bool(false)]);
        Assert::same($weak[0]->receiver['via'] ?? null, 'ctor');
        # Variadic: the base arguments plus one value.
        Assert::true(\in_array([Recipes::bool(false), Recipes::bool(true)], \array_map(static fn(Input $i): array => $i->args, $weak), true));
        # A `use` value of its own.
        Assert::true(\in_array(['k' => Recipes::bool(true)], \array_map(static fn(Input $i): array => $i->uses, $weak), true));
        # The second receiver: through properties.
        Assert::true(\in_array('props', \array_map(static fn(Input $i): ?string => $i->receiver['via'] ?? null, $weak), true));
        Assert::same(\count($weak), \count(\array_unique(\array_map(Recipes::key(...), $weak))));
    }

    public function planIsLimitedAcrossItsWholeLength(): void
    {
        $signature = new Signature([new Parameter('a', TypeSpec::of(TypeSpec::INT)), new Parameter('b', TypeSpec::of(TypeSpec::STRING))]);
        $full = self::planner($signature, '<?php function f($a, $b) { return 1; }')->plan();
        $limited = self::planner($signature, '<?php function f($a, $b) { return 1; }', planLimit: 10)->plan();
        $exact = self::planner($signature, '<?php function f($a, $b) { return 1; }', planLimit: \count($full))->plan();

        Assert::same(\count($limited), 10);
        Assert::same($limited[0]->toArray(), $full[0]->toArray());
        Assert::same($limited[9]->toArray(), $full[\intdiv(9 * \count($full), 10)]->toArray());
        Assert::same(\count($exact), \count($full));
        # The base, 15 int edges and the body's 1, 0, 2 without duplicates, 24 string edges and '2'.
        Assert::same(\count($full), 1 + 14 + 24);
    }

    public function randomInputsRespectTheSignature(): void
    {
        $signature = new Signature(
            [new Parameter('a', TypeSpec::of(TypeSpec::INT)), new Parameter('b', TypeSpec::of(TypeSpec::INT), optional: true), new Parameter('more', TypeSpec::of(TypeSpec::INT), optional: true, variadic: true)],
            [new Parameter('k', TypeSpec::of(TypeSpec::INT))],
            'App\\Money',
        );
        $planner = self::planner($signature, '<?php function f($a, $b = 1, ...$more) { return 1; }', mixStrict: true);
        $planner->fuzz();
        $random = new CoreRandom(new Random(9));
        $counts = [];
        $strict = 0;
        for ($i = 0; $i < 300; ++$i) {
            $input = $planner->generate($random);
            $counts[\count($input->args)] = true;
            $strict += $input->strict ? 1 : 0;
            Assert::true(\count($input->args) >= 1 && \count($input->args) <= 4);
            Assert::same(\array_keys($input->uses), ['k']);
            Assert::notNull($input->receiver);
        }

        \ksort($counts);
        Assert::same(\array_keys($counts), [1, 2, 3, 4]);
        Assert::true($strict > 60 && $strict < 140, "strict: {$strict}");
    }

    public function fuzzMutatesOneArgumentOfAnInputThatOpenedABranch(): void
    {
        $signature = new Signature([new Parameter('a', TypeSpec::of(TypeSpec::INT)), new Parameter('b', TypeSpec::of(TypeSpec::INT))]);
        $feedback = new Feedback(3);
        $seed = new Input([Recipes::int(123456789), Recipes::int(987654321)]);
        $feedback->record($seed, [0, 1]);
        $planner = self::planner($signature, '<?php function f($a, $b) { return 1; }', feedback: $feedback);
        $planner->fuzz();
        $random = new CoreRandom(new Random(4));
        $mutants = 0;
        for ($i = 0; $i < 200; ++$i) {
            $args = $planner->generate($random)->args;
            $kept = (int) ($args[0] === Recipes::int(123456789)) + (int) (($args[1] ?? null) === Recipes::int(987654321));
            $kept === 1 and ++$mutants;
        }

        # Three draws in four mutate an input of the pool, changing one argument.
        Assert::true($mutants > 100, "mutants: {$mutants}");
        Assert::same($planner->plan(), []);
    }

    /**
     * @param positive-int $planLimit
     */
    private static function planner(Signature $signature, string $code, bool $mixStrict = false, int $planLimit = 300, ?Feedback $feedback = null): InputPlanner
    {
        $function = (new NodeFinder())->findFirstInstanceOf((new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [], Function_::class);
        \assert($function instanceof Function_);
        $classes = new class implements ClassInfoProvider {
            public function info(string $class): ?array
            {
                return match ($class) {
                    'App\Clock' => ['exists' => true, 'name' => $class, 'kind' => 'interface', 'methods' => []],
                    'App\Money' => ['exists' => true, 'name' => $class, 'kind' => 'class', 'instantiable' => true, 'props' => [], 'constructor' => [
                        'params' => [['name' => 'amount', 'type' => ['name' => 'int', 'builtin' => true, 'nullable' => false], 'optional' => false]],
                        'doc' => null,
                        'visibility' => 'public',
                    ]],
                    default => null,
                };
            }
        };

        return new InputPlanner($signature, new ValueGenerator($classes, LiteralPool::collect($function)), $feedback ?? new Feedback(1), $mixStrict, $planLimit);
    }
}
