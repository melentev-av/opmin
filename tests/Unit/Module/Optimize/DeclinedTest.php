<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize;

use Internal\Path;
use Opmin\Module\Optimize\Review\Declined;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `opmin.baseline.yaml`: the changes declined in the review.
 */
#[Test]
#[Covers(Declined::class)]
final class DeclinedTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-declined-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function noFileDeclinesNothing(): void
    {
        $declined = Declined::load(Path::create($this->dir));

        Assert::same($declined->all(), []);
        Assert::null($declined->save());
        Assert::false(\is_file($this->dir . '/opmin.baseline.yaml'));
    }

    public function matchesByAnyNameOfTheRuleIgnoringCase(): void
    {
        $this->write("rejected:\n  - { function: '\\App\\Cart::total', rule: FQN }\n  - { function: 'App\\Cart::sum', rule: 'Opmin\\Rector\\Rule\\HoistLoopInvariantCountRector' }\n");

        $declined = Declined::load(Path::create($this->dir));

        Assert::true($declined->has('App\Cart::total', ['fqn', 'fullyqualifyglobalcallsrector']));
        Assert::true($declined->has('app\cart::TOTAL', ['fqn']));
        Assert::false($declined->has('App\Cart::total', ['llm']));
        Assert::true($declined->has('App\Cart::sum', ['count', 'hoistloopinvariantcountrector', '\Opmin\Rector\Rule\HoistLoopInvariantCountRector']));
        Assert::false($declined->has('App\Cart::other', ['fqn']));
    }

    public function savesSortedEntriesAndKeepsOtherKeys(): void
    {
        $this->write("rejected:\n  - { function: 'App\\B::f', rule: fqn }\nfuture: { key: 1 }\n");
        $declined = Declined::load(Path::create($this->dir));

        $declined->add('App\A::f', 'llm');
        $declined->add('App\B::f', 'fqn');
        $file = $declined->save();

        Assert::same((string) $file, (string) Path::create($this->dir)->join('opmin.baseline.yaml'));
        Assert::same((string) \file_get_contents($this->dir . '/opmin.baseline.yaml'), <<<'YAML'
            # Changes declined in `opmin optimize --review`: opmin does not propose them again.
            # Remove an entry to let opmin try the change again.
            rejected:
              - { function: 'App\A::f', rule: llm }
              - { function: 'App\B::f', rule: fqn }
            future:
              key: 1

            YAML);
        Assert::null($declined->save());
    }

    #[DataSet(["rejected: {a: 1}\n", '`rejected` must be a list'], 'not a list')]
    #[DataSet(["rejected:\n  - { function: f }\n", '`rejected.0` must have non-empty `function` and `rule`'], 'no rule')]
    #[DataSet(["rejected:\n  - { function: '\\', rule: fqn }\n", '`rejected.0` must have'], 'empty function')]
    #[DataSet(["- a\n", 'expected a mapping'], 'a list at the top')]
    #[DataSet(["rejected: [\n", 'opmin.baseline.yaml: '], 'broken YAML')]
    public function rejectsInvalidFiles(string $yaml, string $message): never
    {
        $this->write($yaml);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        Declined::load(Path::create($this->dir));
    }

    private function write(string $yaml): void
    {
        \file_put_contents($this->dir . '/opmin.baseline.yaml', $yaml);
    }
}
