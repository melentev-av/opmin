<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Verification\Property\Core;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Verification\Input;
use Opmin\Module\Verification\Property\Core\CorePropertyRunner;
use Opmin\Module\Verification\Property\Core\InputArbitrary;
use Opmin\Module\Verification\Property\Core\InputExecutor;
use Opmin\Module\Verification\Property\Discard;
use Opmin\Module\Verification\Property\InputGenerator;
use Opmin\Module\Verification\Property\InputShrinker;
use Opmin\Module\Verification\Property\PropertySpec;
use Opmin\Module\Verification\Property\RandomSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(CorePropertyRunner::class)]
#[Covers(InputArbitrary::class)]
#[Covers(InputExecutor::class)]
#[Covers(\Opmin\Module\Verification\Property\PropertyOutcome::class)]
#[Covers(\Opmin\Module\Verification\Input::class)]
final class CorePropertyRunnerTest
{
    private ?Path $dir = null;

    public static function input(int $value): Input
    {
        return new Input([['type' => 'int', 'value' => $value]]);
    }

    public static function int(?Input $input): int
    {
        $value = $input?->args[0]['value'] ?? null;
        \assert(\is_int($value));

        return $value;
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        $this->dir === null or FS::remove($this->dir);
    }

    public function shrinksCounterexampleToTheMinimum(): void
    {
        $outcome = (new CorePropertyRunner())->run(
            new PropertySpec('f', runs: 200, seed: 42),
            self::ints(),
            self::shrinker(),
            static function (Input $input): void {
                self::int($input) > 37 and throw new \RuntimeException('differs');
            },
        );

        Assert::true($outcome->isFalsified());
        Assert::same(self::int($outcome->shrunk), 38);
        Assert::true(self::int($outcome->original) >= 38);
        Assert::same($outcome->failure?->getMessage(), 'differs');
        Assert::false($outcome->flaky);
    }

    public function holdsWhenEveryInputPasses(): void
    {
        $seen = 0;

        $outcome = (new CorePropertyRunner())->run(
            new PropertySpec('f', runs: 50, seed: 1),
            self::ints(),
            self::shrinker(),
            static function () use (&$seen): void {
                ++$seen;
            },
        );

        Assert::false($outcome->isFalsified());
        Assert::same([$outcome->checks, $seen], [50, 50]);
    }

    public function checksExamplesFirst(): void
    {
        $order = [];

        (new CorePropertyRunner())->run(
            new PropertySpec('f', runs: 3, seed: 1, examples: [self::input(7), self::input(-3)]),
            self::ints(),
            self::shrinker(),
            static function (Input $input) use (&$order): void {
                $order[] = self::int($input);
            },
        );

        Assert::same(\array_slice($order, 0, 2), [7, -3]);
    }

    public function discardedInputsDoNotCount(): void
    {
        $outcome = (new CorePropertyRunner())->run(
            new PropertySpec('f', runs: 20, seed: 3),
            self::ints(),
            self::shrinker(),
            static function (Input $input): void {
                self::int($input) % 2 === 0 and throw new Discard();
            },
        );

        Assert::same([$outcome->checks, $outcome->gaveUp], [20, false]);
    }

    public function replaysCorpusFirst(): void
    {
        $this->dir = FS::tmpDir(sub: 'opmin-corpus');
        $spec = new PropertySpec('f', runs: 100, seed: 5, corpus: $this->dir);
        $check = static function (Input $input): void {
            self::int($input) >= 900 and throw new \RuntimeException('differs');
        };
        $first = (new CorePropertyRunner())->run($spec, self::ints(), self::shrinker(), $check);
        $generated = 0;
        $counting = new class($generated) implements InputGenerator {
            public function __construct(private int &$count) {}

            public function generate(RandomSource $random): Input
            {
                ++$this->count;
                return new Input([['type' => 'int', 'value' => 0]]);
            }
        };

        $second = (new CorePropertyRunner())->run($spec, $counting, self::shrinker(), $check);

        Assert::true($first->isFalsified());
        Assert::true($second->isFalsified());
        Assert::same(self::int($second->shrunk), 900);
        Assert::same($generated, 0);
    }

    public function timeBudgetEndsTheRunWithoutAFailure(): void
    {
        $outcome = (new CorePropertyRunner())->run(
            new PropertySpec('f', runs: 1_000_000, seed: 1, budgetMs: 50),
            self::ints(),
            self::shrinker(),
            static function (): void {
                \usleep(1000);
            },
        );

        Assert::false($outcome->isFalsified());
        Assert::false($outcome->gaveUp);
        Assert::true($outcome->checks > 0 && $outcome->checks < 1_000_000);
    }

    public function discardingEverythingGivesUp(): void
    {
        $outcome = (new CorePropertyRunner())->run(
            new PropertySpec('f', runs: 5, seed: 1),
            self::ints(),
            self::shrinker(),
            static function (): void {
                throw new Discard();
            },
        );

        Assert::same([$outcome->gaveUp, $outcome->checks, $outcome->isFalsified()], [true, 0, false]);
    }

    public function noShrinkingWhenMaxShrinksIsZero(): void
    {
        $outcome = (new CorePropertyRunner())->run(
            new PropertySpec('f', runs: 200, seed: 42, maxShrinks: 0),
            self::ints(),
            self::shrinker(),
            static function (Input $input): void {
                self::int($input) > 37 and throw new \RuntimeException('differs');
            },
        );

        Assert::true($outcome->isFalsified());
        Assert::same(self::int($outcome->shrunk), self::int($outcome->original));
        Assert::same($outcome->shrinkSteps, 0);
        Assert::true($outcome->checks >= 0);
    }

    public function corpusReplayReportsTheStoredInput(): void
    {
        $this->dir = FS::tmpDir(sub: 'opmin-corpus');
        $corpus = $this->dir->join('nested', 'corpus');
        $spec = new PropertySpec('g', runs: 100, seed: 5, corpus: $corpus);
        $check = static function (Input $input): void {
            self::int($input) >= 900 and throw new \RuntimeException('differs', 7);
        };
        (new CorePropertyRunner())->run($spec, self::ints(), self::shrinker(), $check);

        $replayed = (new CorePropertyRunner())->run($spec, self::ints(), self::shrinker(), $check);

        Assert::true($corpus->isDir());
        Assert::same([$replayed->checks, $replayed->shrinkSteps, $replayed->flaky], [0, 0, false]);
        Assert::same($replayed->failure?->getMessage(), 'differs');
        Assert::same($replayed->original?->toArray(), $replayed->shrunk?->toArray());
    }

    private static function ints(): InputGenerator
    {
        return new class implements InputGenerator {
            public function generate(RandomSource $random): Input
            {
                return CorePropertyRunnerTest::input($random->int(-1000, 1000));
            }
        };
    }

    private static function shrinker(): InputShrinker
    {
        return new class implements InputShrinker {
            public function candidates(Input $input): iterable
            {
                $value = CorePropertyRunnerTest::int($input);
                foreach (\array_unique([0, \intdiv($value, 2), $value - ($value <=> 0)]) as $candidate) {
                    $candidate === $value or yield CorePropertyRunnerTest::input($candidate);
                }
            }
        };
    }
}
