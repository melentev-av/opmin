<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Property\Core;

use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Property\InputGenerator;
use Opmin\Module\Verification\Property\InputShrinker;
use Opmin\Module\Verification\Property\PropertyOutcome;
use Opmin\Module\Verification\Property\PropertyRunner;
use Opmin\Module\Verification\Property\PropertySpec;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner as EngineRunner;
use Rasuvaeff\PropertyTesting\Runner\RegressionFailed;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;

/**
 * {@see PropertyRunner} on `rasuvaeff/property-testing-core`: the engine's runner, seed, corpus,
 * shrink descent and flaky replays; generation and shrink candidates are opmin's.
 *
 * The property has one parameter — the whole input — so the engine's per-parameter descent is the
 * descent through {@see InputShrinker}. See `docs/testing.md` for how the engine is driven.
 *
 * @internal
 */
final readonly class CorePropertyRunner implements PropertyRunner
{
    public const PARAMETER = 'input';

    public function __construct(
        private EngineRunner $engine = new EngineRunner(),
    ) {}

    public function run(PropertySpec $spec, InputGenerator $generator, InputShrinker $shrinker, \Closure $check): PropertyOutcome
    {
        $corpus = null;
        if ($spec->corpus !== null) {
            FS::mkdir((string) $spec->corpus);
            $corpus = new FilesystemCorpus((string) $spec->corpus);
        }

        $definition = new PropertyDefinition(
            id: $spec->id,
            name: $spec->id,
            generators: [self::PARAMETER => new InputArbitrary($generator, $shrinker, $spec->examples)],
            parameterNames: [self::PARAMETER],
            config: new PropertyConfig(
                runs: $spec->runs,
                seed: $spec->seed,
                maxShrinks: $spec->maxShrinks,
                budgetMs: $spec->budgetMs,
                phases: $spec->maxShrinks === 0 ? [Phase::Corpus, Phase::Random] : [Phase::Corpus, Phase::Random, Phase::Shrink],
            ),
        );

        $result = $this->engine->run($definition, new InputExecutor($check), corpus: $corpus);

        return match (true) {
            $result instanceof Passed => PropertyOutcome::held(\max(0, $result->statistics->checks), $spec->seed),
            $result instanceof TimeBudgetExceeded => PropertyOutcome::held(\max(0, $result->statistics->checks), $spec->seed),
            $result instanceof GaveUp => PropertyOutcome::held(\max(0, $result->statistics->checks), $spec->seed, gaveUp: true),
            $result instanceof Falsified => $this->falsified($result, $spec->seed),
            $result instanceof RegressionFailed => $this->regression($result),
            # Generation errors, deadlines and coverage requirements are not used by opmin: a bug.
            default => throw new \LogicException(
                "Property {$spec->id}: unexpected engine result " . $result::class,
                previous: $result->failure(),
            ),
        };
    }

    private function falsified(Falsified $result, int $seed): PropertyOutcome
    {
        $example = $result->counterExample();
        /** @var array<array-key, mixed> $original */
        $original = $example->originalArguments[self::PARAMETER];
        /** @var array<array-key, mixed> $shrunk */
        $shrunk = $example->shrunkArguments[self::PARAMETER];

        return PropertyOutcome::falsified(
            checks: \max(0, $example->runsBeforeFailure),
            seed: $seed,
            original: Input::fromArray($original),
            shrunk: Input::fromArray($shrunk),
            failure: $example->failure ?? $result->exception,
            shrinkSteps: $example->shrinkSteps,
            flaky: $example->isFlaky(),
        );
    }

    /**
     * A failing input stored in the corpus by an earlier run still fails: it was shrunk then.
     */
    private function regression(RegressionFailed $result): PropertyOutcome
    {
        /** @var array<array-key, mixed> $arguments */
        $arguments = $result->exception->getArguments()[self::PARAMETER];
        $input = Input::fromArray($arguments);

        return PropertyOutcome::falsified(
            checks: 0,
            seed: $result->exception->getSeed(),
            original: $input,
            shrunk: $input,
            failure: $result->exception->getPrevious() ?? $result->exception,
            shrinkSteps: 0,
            flaky: false,
        );
    }
}
