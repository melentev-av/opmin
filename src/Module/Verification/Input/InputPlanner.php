<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Property\InputGenerator;
use Opmin\Module\Verification\Property\RandomSource;

/**
 * Inputs of a differential test in three phases (brief 2.4): first a deterministic plan — boundary
 * values of every parameter's type, values outside the type, literals of the body — then random
 * inputs, and in fuzz mode mutations of the inputs that opened new branches.
 *
 * @internal
 */
final class InputPlanner implements InputGenerator
{
    private const PER_PARAMETER = 48;

    /** @var list<Input>|null */
    private ?array $plan = null;

    private bool $fuzz = false;

    /**
     * @param bool $mixStrict Also call from a `strict_types=1` file (the signatures of the versions differ).
     * @param positive-int $planLimit
     */
    public function __construct(
        private readonly Signature $signature,
        private readonly ValueGenerator $values,
        private readonly Feedback $feedback,
        private readonly bool $mixStrict = false,
        private readonly int $planLimit = 300,
    ) {}

    /**
     * Inputs of the deterministic phases.
     *
     * @return list<Input>
     */
    public function plan(): array
    {
        return $this->plan ??= $this->buildPlan();
    }

    /**
     * Fuzz mode: mutations of the inputs that opened new branches (phase 3).
     */
    public function fuzz(bool $enabled = true): void
    {
        $this->fuzz = $enabled;
        $enabled and $this->plan = [];
    }

    public function generate(RandomSource $random): Input
    {
        $plan = $this->plan();
        if ($plan !== []) {
            $this->plan = \array_slice($plan, 1);

            return $plan[0];
        }

        $this->values->beginInput();
        $pool = $this->feedback->pool();
        $input = $this->fuzz && $pool !== [] && $random->int(1, 4) !== 1
            ? $this->mutate($pool[$random->int(0, \count($pool) - 1)], $pool, $random)
            : $this->randomInput($random);

        return Recipes::renumber($input);
    }

    /**
     * @return list<Input>
     */
    private function buildPlan(): array
    {
        $params = $this->signature->params;
        $required = $this->signature->required();
        $base = [];
        foreach ($params as $param) {
            $param->variadic or $base[] = $this->first($param->type);
        }

        $receiver = $this->signature->receiver === null ? null : $this->values->edges(new TypeSpec(TypeSpec::CLASS_, $this->signature->receiver))[0] ?? null;
        $uses = [];
        foreach ($this->signature->uses as $use) {
            $uses[$use->name] = $this->first($use->type);
        }

        $inputs = [new Input(\array_slice($base, 0, $required), $receiver, $uses)];
        \count($base) > $required and $inputs[] = new Input($base, $receiver, $uses);

        foreach ($params as $i => $param) {
            foreach ($this->candidates($param) as $candidate) {
                $args = \array_slice($base, 0, \max($required, $i + 1));
                if ($param->variadic) {
                    $args = [...\array_slice($base, 0, $i), $candidate];
                } else {
                    $args[$i] = $candidate;
                }

                $inputs[] = new Input(\array_values($args), $receiver, $uses);
            }
        }

        foreach ($this->signature->uses as $use) {
            foreach ($this->candidates($use) as $candidate) {
                $inputs[] = new Input(\array_slice($base, 0, $required), $receiver, [$use->name => $candidate] + $uses);
            }
        }

        if ($this->signature->receiver !== null) {
            foreach (\array_slice($this->values->edges(new TypeSpec(TypeSpec::CLASS_, $this->signature->receiver)), 1, 3) as $other) {
                $inputs[] = new Input(\array_slice($base, 0, $required), $other, $uses);
            }
        }

        $this->mixStrict and $inputs = $this->withStrict($inputs);

        return $this->limit($this->unique($inputs));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(Parameter $param): array
    {
        $candidates = \array_slice($this->values->edges($param->type), 0, 24);
        \array_push($candidates, ...\array_slice($this->values->literals($param->type), 0, 20));
        $param->typed and \array_push($candidates, ...$this->values->offType($param->type));

        return \array_slice($candidates, 0, self::PER_PARAMETER);
    }

    /**
     * @return array<string, mixed>
     */
    private function first(TypeSpec $type): array
    {
        return $this->values->edges($type)[0] ?? Recipes::null();
    }

    private function randomInput(RandomSource $random): Input
    {
        $receiver = $this->signature->receiver === null ? null : $this->values->random(new TypeSpec(TypeSpec::CLASS_, $this->signature->receiver), $random);
        $uses = [];
        foreach ($this->signature->uses as $use) {
            $uses[$use->name] = $this->values->random($use->type, $random);
        }

        $args = [];
        $required = $this->signature->required();
        foreach ($this->signature->params as $i => $param) {
            if ($param->variadic) {
                for ($n = $random->int(0, 2); $n > 0; --$n) {
                    $args[] = $this->values->random($param->type, $random);
                }

                break;
            }

            if ($i >= $required && $random->int(1, 10) <= 3) {
                break;
            }

            $args[] = $this->values->random($param->type, $random);
        }

        return new Input($args, $receiver, $uses, $this->mixStrict && $random->int(1, 3) === 1);
    }

    /**
     * One argument (or `$this`, or a `use` value) of an input that opened a new branch is changed:
     * a fresh random value, a value of another such input, or a boundary or literal value.
     *
     * @param list<Input> $pool
     */
    private function mutate(Input $input, array $pool, RandomSource $random): Input
    {
        $targets = \count($input->args) + ($input->receiver === null ? 0 : 1) + \count($input->uses);
        if ($targets === 0) {
            return $this->randomInput($random);
        }

        $target = $random->int(0, $targets - 1);
        if ($target < \count($input->args)) {
            $param = $this->signature->params[\min($target, \count($this->signature->params) - 1)] ?? null;
            $type = $param?->type ?? TypeSpec::mixed();
            $other = $pool[$random->int(0, \count($pool) - 1)];
            $args = $input->args;
            $args[$target] = match ($random->int(1, 5)) {
                1 => $other->args[$target] ?? $this->values->random($type, $random),
                2 => $this->pick([...$this->values->edges($type), ...$this->values->literals($type)], $random) ?? $this->values->random($type, $random),
                default => $this->values->random($type, $random),
            };

            return $input->withArgs(\array_values($args));
        }

        if ($input->receiver !== null && $target === \count($input->args)) {
            return $input->withReceiver($this->values->random(new TypeSpec(TypeSpec::CLASS_, (string) $this->signature->receiver), $random));
        }

        $uses = $input->uses;
        foreach ($this->signature->uses as $use) {
            if ($random->int(0, 1) === 0) {
                $uses[$use->name] = $this->values->random($use->type, $random);
            }
        }

        return $input->withUses($uses);
    }

    /**
     * @param list<array<string, mixed>> $values
     * @return array<string, mixed>|null
     */
    private function pick(array $values, RandomSource $random): ?array
    {
        return $values === [] ? null : $values[$random->int(0, \count($values) - 1)];
    }

    /**
     * Every input also from a strict caller, right after the weak one.
     *
     * @param list<Input> $inputs
     * @return list<Input>
     */
    private function withStrict(array $inputs): array
    {
        $result = [];
        foreach ($inputs as $input) {
            $result[] = $input;
            $result[] = $input->withStrict(true);
        }

        return $result;
    }

    /**
     * @param list<Input> $inputs
     * @return list<Input>
     */
    private function unique(array $inputs): array
    {
        $seen = [];
        $result = [];
        foreach ($inputs as $input) {
            $input = Recipes::renumber($input);
            $key = Recipes::key($input);
            isset($seen[$key]) or $result[] = $input;
            $seen[$key] = true;
        }

        return $result;
    }

    /**
     * At most {@see self::$planLimit} inputs, spread over the whole plan.
     *
     * @param list<Input> $inputs
     * @return list<Input>
     */
    private function limit(array $inputs): array
    {
        $count = \count($inputs);
        if ($count <= $this->planLimit) {
            return $inputs;
        }

        $result = [];
        for ($i = 0; $i < $this->planLimit; ++$i) {
            $result[] = $inputs[\intdiv($i * $count, $this->planLimit)];
        }

        return $result;
    }
}
