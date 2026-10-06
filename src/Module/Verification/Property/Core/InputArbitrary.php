<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property\Core;

use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Property\InputGenerator;
use Opmin\Module\Verification\Property\InputShrinker;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * The engine's arbitrary over opmin's generator and shrinker.
 *
 * Values are input arrays ({@see Input::toArray()}), not objects: the engine stores array values in
 * the corpus verbatim and replays them as one run. The shrink tree is built from
 * {@see InputShrinker}, lazily.
 *
 * @implements ArbitraryInterface<array<array-key, mixed>>
 * @internal
 */
final class InputArbitrary implements ArbitraryInterface
{
    /**
     * @param list<Input> $examples Returned by the first draws, before the generator is asked.
     */
    public function __construct(
        private readonly InputGenerator $generator,
        private readonly InputShrinker $shrinker,
        private array $examples = [],
    ) {}

    public function generate(Random $random): Shrinkable
    {
        $input = \array_shift($this->examples) ?? $this->generator->generate(new CoreRandom($random));

        return $this->tree($input);
    }

    /**
     * @return Shrinkable<array<array-key, mixed>>
     */
    private function tree(Input $input): Shrinkable
    {
        $shrinker = $this->shrinker;

        return Shrinkable::of($input->toArray(), function () use ($input, $shrinker): \Generator {
            foreach ($shrinker->candidates($input) as $candidate) {
                yield $this->tree($candidate);
            }
        });
    }
}
