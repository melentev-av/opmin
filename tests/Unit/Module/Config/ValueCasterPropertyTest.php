<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Config;

use Opmin\Module\Config\ConfigSchema;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\ValueCaster;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ValueCaster::class)]
final class ValueCasterPropertyTest
{
    /**
     * @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface>
     */
    public static function intRoundTripsThroughStringGenerators(): array
    {
        return ['value' => Gen::int()];
    }

    /**
     * @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface>
     */
    public static function nonIntegerStringIsRejectedGenerators(): array
    {
        return ['value' => Gen::string()];
    }

    /**
     * Any int written as a string in the environment or `--set` is read back unchanged.
     */
    #[Property(runs: 300)]
    public function intRoundTripsThroughString(int $value): void
    {
        $key = ConfigSchema::find('verification.seed');
        \assert($key !== null);

        Assert::same(ValueCaster::fromString($key, (string) $value, 'test'), $value);
    }

    /**
     * A string that is not an integer never becomes one silently.
     */
    #[Property(runs: 300)]
    public function nonIntegerStringIsRejected(string $value): void
    {
        $key = ConfigSchema::find('verification.seed');
        \assert($key !== null);

        $isInt = \filter_var(\trim($value), \FILTER_VALIDATE_INT) !== false;
        try {
            $result = ValueCaster::fromString($key, $value, 'test');
            Assert::true($isInt, 'accepted a non-integer string');
            Assert::same($result, (int) \trim($value));
        } catch (ConfigException) {
            Assert::false($isInt, 'rejected an integer string');
        }
    }
}
