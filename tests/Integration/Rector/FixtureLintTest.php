<?php

declare(strict_types=1);

namespace Opmin\Tests\Integration\Rector;

use Opmin\Tests\Integration\TestPhp;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The code opmin's rules write compiles under `php.binary`: `php -l` on the expected output of
 * every positive fixture. A fixture compared as text only once hid a printed `.` that lost the
 * parentheses of its operand (a nested ternary without them is a compile error).
 */
#[Test]
final class FixtureLintTest
{
    private const FIXTURES = __DIR__ . '/../../../src/Rector/Rule/Fixture';

    /**
     * @return iterable<string, array{string}>
     */
    public static function expectedOutputs(): iterable
    {
        foreach (\glob(self::FIXTURES . '/*/*.php.inc') ?: [] as $file) {
            $parts = \explode("\n-----\n", (string) \file_get_contents($file));
            if (\count($parts) === 2) {
                yield \basename(\dirname($file)) . '/' . \basename($file, '.php.inc') => [$parts[1]];
            }
        }
    }

    #[DataProvider('expectedOutputs')]
    public function expectedOutputCompiles(string $code): void
    {
        $file = \sys_get_temp_dir() . '/opmin-lint-' . \bin2hex(\random_bytes(4)) . '.php';
        \file_put_contents($file, $code);
        try {
            \exec(\escapeshellarg(TestPhp::path()) . ' -l ' . \escapeshellarg($file) . ' 2>&1', $output, $exit);
        } finally {
            @\unlink($file);
        }

        Assert::same($exit, 0, \implode("\n", $output));
    }
}
