<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Check;

use Opmin\Module\Check\Baseline;
use Opmin\Module\Check\BaselineException;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Locate\UnitKind;
use Opmin\Module\Opcode\Report\CountReport;
use Internal\Path;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

/**
 * `opmin.baseline.json`: deterministic serialization, reading, compatibility of the counts.
 */
#[Test]
#[Covers(Baseline::class)]
final class BaselineTest
{
    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function serializationDoesNotDependOnTheOrderOfFunctionsGenerators(): array
    {
        return ['functions' => Gen::dictOf(Gen::stringFrom('abAB:{}0', 1, 6), Gen::intBetween(0, 500), 0, 20)];
    }

    /**
     * @param array<string, int> $functions
     */
    #[Property(runs: 200)]
    public function serializationDoesNotDependOnTheOrderOfFunctions(array $functions): void
    {
        $entries = [];
        foreach ($functions as $key => $ops) {
            $entries['App\\' . $key] = ['ops' => $ops, 'file' => 'src/' . \strtolower((string) $key) . '.php'];
        }

        $shuffled = $entries;
        \uksort($shuffled, static fn(string $a, string $b): int => \strcmp(\md5($a), \md5($b)));
        $json = Baseline::create('8.4.1', '1.0.0', 'h', $entries)->toJson();

        Assert::same(Baseline::create('8.4.1', '1.0.0', 'h', $shuffled)->toJson(), $json);
        Assert::same(Baseline::fromJson($json, 'b.json')->toJson(), $json);
    }

    public function keepsOnlyFunctionsWithoutLines(): void
    {
        $report = CountReport::create('1.0.0', '8.4.1', null, 'h', [
            self::count('App\Price::calc', 42, 'src/Price.php', 30),
            self::count('App\Price::calc::{closure:1}', 7, 'src/Price.php', 31),
            self::count('src/routes.php::<main>', 90, 'src/routes.php', 1, optimizable: false),
        ]);

        $json = Baseline::fromReport($report)->toJson();

        Assert::same($json, <<<'JSON'
            {
                "php": "8.4.1",
                "opmin": "1.0.0",
                "optimizer_hash": "h",
                "functions": {
                    "App\\Price::calc": {
                        "ops": 42,
                        "file": "src/Price.php"
                    },
                    "App\\Price::calc::{closure:1}": {
                        "ops": 7,
                        "file": "src/Price.php"
                    }
                }
            }

            JSON);
    }

    public function emptyBaselineKeepsAnObject(): void
    {
        Assert::string(Baseline::create('8.4.1', '1', 'h', [])->toJson())->contains('"functions": {}');
    }

    public function replacesAndRemovesEntries(): void
    {
        $baseline = Baseline::create('8.4.1', '1.0.0', 'h', [
            'b' => ['ops' => 2, 'file' => 'b.php'],
            'a' => ['ops' => 1, 'file' => 'a.php'],
        ]);

        $updated = $baseline->with(['a' => null, 'c' => ['ops' => 3, 'file' => 'c.php'], 'b' => ['ops' => 1, 'file' => 'b.php']], '1.1.0');

        Assert::same($updated->functions, ['b' => ['ops' => 1, 'file' => 'b.php'], 'c' => ['ops' => 3, 'file' => 'c.php']]);
        Assert::same([$updated->opmin, $updated->php, $updated->optimizerHash], ['1.1.0', '8.4.1', 'h']);
    }

    #[DataSet(['8.4.1', 'h', null], 'same')]
    #[DataSet(['8.4.9', 'h', null], 'another patch version')]
    #[DataSet(['8.3.1', 'h', 'taken with PHP 8.4.1, php.binary is PHP 8.3.1'], 'another minor version')]
    #[DataSet(['8.4.1', 'x', 'other optimizer settings (optimizer_hash h, now x'], 'another optimizer hash')]
    public function comparesOnlyCountsOfTheSamePhpMinorAndOptimizer(string $php, string $hash, ?string $message): void
    {
        $reason = Baseline::create('8.4.1', '1', 'h', [])->incompatibility($php, $hash);

        $message === null ? Assert::null($reason) : Assert::string((string) $reason)->contains($message);
    }

    #[DataSet(['{"php": "8.4.1"}', 'no `php`, `optimizer_hash` or `functions`'], 'no functions')]
    #[DataSet(['{"php": "8.4.1", "optimizer_hash": "h", "functions": {"f": {"ops": -1, "file": "a.php"}}}', 'function `f` needs'], 'negative ops')]
    #[DataSet(['{"php": "8.4.1", "optimizer_hash": "h", "functions": {"f": {"ops": 1}}}', 'function `f` needs `ops` and `file`'], 'no file')]
    #[DataSet(['[', 'Syntax error'], 'broken JSON')]
    public function rejectsBrokenFiles(string $json, string $message): never
    {
        Expect::exception(BaselineException::class)->withMessageContaining('b.json is not an opmin baseline: ' . $message);

        Baseline::fromJson($json, 'b.json');
    }

    public function missingFileTellsHowToCreateIt(): never
    {
        Expect::exception(BaselineException::class)->withMessageContaining('Create it with `opmin baseline`');

        Baseline::load(Path::create(\sys_get_temp_dir())->join('opmin-no-such-dir', Baseline::FILE));
    }

    /**
     * @param non-empty-string $key
     * @param int<0, max> $ops
     * @param non-empty-string $file
     */
    private static function count(string $key, int $ops, string $file, int $line, bool $optimizable = true): FunctionCount
    {
        return new FunctionCount($key, $optimizable ? UnitKind::Method : UnitKind::Main, $file, $line, $ops, $ops, 0, 0, 0, [], $optimizable);
    }
}
