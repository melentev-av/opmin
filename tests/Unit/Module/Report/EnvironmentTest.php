<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Report;

use Internal\Path;
use Opmin\Module\Report\Environment;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Environment::class)]
final class EnvironmentTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-env-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/runs', 0777, true);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function warnsAboutEveryComparedChange(): void
    {
        $was = new Environment('0.1.0', '8.4.11', '8.3', 'aaa', '2.6.0', null, 'phpunit', 'pint');
        $now = new Environment('0.2.0', '8.4.12', '8.3', 'aaa', '2.7.0', '2.1.30', 'pest', 'none');

        $warnings = $now->changesSince($was, '20261006-100000');

        Assert::same($warnings, [
            'php changed since the run 20261006-100000: 8.4.11 → 8.4.12 (opcode counts are not comparable).',
            'opmin changed since the run 20261006-100000: 0.1.0 → 0.2.0 (the rules and the checks may differ).',
            'rector changed since the run 20261006-100000: 2.6.0 → 2.7.0 (the same rule may rewrite code differently).',
            'phpstan changed since the run 20261006-100000: none → 2.1.30 (the static check may see other errors).',
        ]);
        Assert::same($now->changesSince($now, 'x'), []);
    }

    public function roundTripsThroughArray(): void
    {
        $environment = new Environment('0.2.0', '8.4.12', null, 'aaa', '2.7.0', '2.1.30', null, 'none');

        Assert::same(Environment::fromArray($environment->toArray())->toArray(), $environment->toArray());
    }

    public function previousIsTheLatestOtherRunWithAReport(): void
    {
        $runs = Path::create($this->dir . '/runs');
        $this->run('20261006-100000', ['environment' => ['php' => '8.4.1']]);
        $this->run('20261006-120000', ['environment' => ['php' => '8.4.2']]);
        $this->run('20261006-130000', ['no_environment' => true]);
        \mkdir($this->dir . '/runs/20261006-140000');

        $previous = Environment::previous($runs, $runs->join('20261006-120000'));

        Assert::same($previous === null ? null : [$previous[0], $previous[1]->php], ['20261006-100000', '8.4.1']);
        Assert::same((Environment::previous($runs) ?? ['none'])[0], '20261006-120000');
        Assert::null(Environment::previous(Path::create($this->dir . '/none')));
    }

    /**
     * @param array<string, mixed> $report
     */
    private function run(string $name, array $report): void
    {
        \mkdir($this->dir . '/runs/' . $name);
        \file_put_contents($this->dir . '/runs/' . $name . '/report.json', \json_encode($report, \JSON_THROW_ON_ERROR));
    }
}
