<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property;

use Internal\Path;
use Opmin\Module\Verification\Input;

/**
 * What to run: identity, number of inputs, seed, limits, corpus.
 *
 * @internal
 */
final readonly class PropertySpec
{
    /**
     * @param non-empty-string $id Stable identity (the function key): keys the corpus.
     * @param positive-int $runs Inputs to check (discards do not count).
     * @param list<Input> $examples Inputs checked first, before the generated ones.
     * @param positive-int|null $budgetMs Wall-clock limit of the generated inputs; reaching it is
     *        not a failure — the property holds for what was checked.
     * @param non-negative-int $maxShrinks Accepted shrink steps; 0 — no shrinking.
     * @param Path|null $corpus Directory of the regression corpus: failing inputs are stored there and
     *        replayed first next time; null — no corpus.
     */
    public function __construct(
        public string $id,
        public int $runs,
        public int $seed,
        public array $examples = [],
        public ?int $budgetMs = null,
        public int $maxShrinks = 1000,
        public ?Path $corpus = null,
    ) {}
}
