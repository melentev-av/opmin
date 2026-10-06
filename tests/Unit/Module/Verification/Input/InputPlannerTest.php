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

    private static function planner(Signature $signature, string $code): InputPlanner
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

        return new InputPlanner($signature, new ValueGenerator($classes, LiteralPool::collect($function)), new Feedback(1));
    }
}
