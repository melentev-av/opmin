<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Module\Verification;

use Internal\Path;
use Opmin\Module\Config\Schema\GuardCost;
use Opmin\Module\Verification\Cost;
use Opmin\Module\Verification\CostMeter;
use Opmin\Module\Verification\Input;
use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `--guard-cost` under a real callgrind: `valgrind` on the PATH (Linux), or `OPMIN_TEST_VALGRIND` — a
 * wrapper that runs it where php.binary runs — with `OPMIN_TEST_VALGRIND_PHP`, the PHP it starts there:
 *
 *     OPMIN_TEST_VALGRIND=$PWD/runtime/valgrind-8.5 OPMIN_TEST_VALGRIND_PHP=php vendor/bin/testo --filter=CostMeterTest
 *
 * The files of the test lie in the repository (runtime/), which a Docker wrapper mounts.
 */
#[Test]
#[Covers(CostMeter::class)]
final class CostMeterTest
{
    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = __DIR__ . '/../../../../runtime/cost-meter-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0777, true);
        $this->dir = (string) \realpath($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function cheaperCodeCostsLessInBothModes(): void
    {
        $cost = $this->measure(
            "return \$a . '/' . \$b . '/' . \$c;",
            'return "{$a}/{$b}/{$c}";',
        );

        Assert::same(\array_keys($cost->modes), [Cost::OFF, Cost::TRACING]);
        foreach ($cost->toArray() as $mode => $mean) {
            Assert::true($mean['after'] < $mean['before'], "{$mode}: " . \json_encode($mean));
        }

        Assert::null($cost->regression(5, 15));
    }

    public function costlierCodeIsARegression(): void
    {
        $cost = $this->measure(
            "return \$a . '/' . \$b . '/' . \$c;",
            "\$s = ''; foreach ([\$a, \$b, \$c] as \$i => \$p) { \$s .= (\$i === 0 ? '' : '/') . \\strtolower(\\strtoupper(\$p)); } return \$s;",
        );

        Assert::string((string) $cost->regression(5, 15))->contains('costs more without the JIT');
    }

    private function measure(string $before, string $after): Cost
    {
        $config = new GuardCost();
        $config->enabled = true;
        $wrapper = \getenv('OPMIN_TEST_VALGRIND');
        \is_string($wrapper) && $wrapper !== '' and $config->valgrind = $wrapper;
        $php = \getenv('OPMIN_TEST_VALGRIND_PHP');
        \is_string($php) && $php !== '' and $config->php = $php;
        $meter = new CostMeter(TestPhp::binary(), $config, '256M');
        $meter->unavailable() === null or throw new SkipTest('No valgrind: set OPMIN_TEST_VALGRIND or install it.');

        $loads = [];
        foreach (['a' => $before, 'b' => $after] as $version => $body) {
            $file = "{$this->dir}/{$version}.php";
            \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfunction path(string \$a, string \$b, string \$c): string { {$body} }\n");
            $loads[] = ['autoload' => null, 'files' => [$file], 'overrides' => [], 'fakes' => ['namespaces' => [], 'time' => 1700000000.0, 'seed' => 1]];
        }

        $target = ['kind' => 'function', 'name' => 'App\path'];
        $string = static fn(string $s): array => ['type' => 'string', 'value' => $s];
        $inputs = [
            new Input([$string('src'), $string('Module'), $string('a.php')]),
            new Input([$string(\str_repeat('x', 100)), $string('y'), $string('z')]),
        ];

        $cost = $meter->measure($loads[0], $loads[1], $target, $target, $inputs, Path::create("{$this->dir}/work"));
        Assert::notNull($cost);

        /** @var Cost $cost */
        return $cost;
    }
}
