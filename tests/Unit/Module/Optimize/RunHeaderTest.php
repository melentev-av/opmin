<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Optimize;

use Opmin\Module\Optimize\RunHeader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RunHeader::class)]
final class RunHeaderTest
{
    /**
     * @return iterable<string, array{?string, bool, bool, string, ?string}>
     *         [runner, selective, coverage driver, the tests line, the start of the warning]
     */
    public static function runners(): iterable
    {
        yield 'phpunit with a driver' => ['phpunit', true, true, '  Tests: phpunit, the tests that cover a changed function', null];
        yield 'phpunit without a driver' => ['phpunit', true, false, '  Tests: phpunit, the whole suite on every step', 'Every step runs the whole test suite: php.binary has neither pcov nor Xdebug'];
        yield 'a plain command with a driver' => ['command', false, true, '  Tests: command (tests.command): the whole suite on every step', 'Every step runs the whole test suite: tests.command is a plain command'];
        yield 'a plain command without a driver' => ['command', false, false, '  Tests: command (tests.command): the whole suite on every step', 'Every step runs the whole test suite: tests.command is a plain command'];
        yield 'no runner' => [null, false, false, '  Tests: none: behavior is checked by the differential tests only', null];
    }

    #[DataProvider('runners')]
    public function saysHowTheTestsRunAndWarnsWhenEveryStepRunsTheWholeSuite(?string $runner, bool $selective, bool $driver, string $line, ?string $warning): void
    {
        $header = self::header($runner, $selective, $driver);

        $lines = $header->lines();
        $actual = $header->warning();

        Assert::same($lines[3], $line);
        Assert::same($lines[4], $driver ? '  Coverage driver: yes (pcov or Xdebug in php.binary)' : '  Coverage driver: no (neither pcov nor Xdebug in php.binary)');
        $warning === null
            ? Assert::null($actual)
            : Assert::string($actual[0] ?? '')->startsWith($warning);
    }

    public function listsWhatTheRunWillUse(): void
    {
        $header = self::header('phpunit', true, true);

        $lines = $header->lines();

        Assert::same($lines, [
            'Optimizing 2 file(s) of /p with 9 rule(s), PHP 8.4.1 (target 8.1), git: a commit per accepted step.',
            '  Functions: 12, 10 of them optimizable',
            '  Rules: R1, R2, R3, R4, R5, R6, R7, R8, … 1 more',
            '  Tests: phpunit, the tests that cover a changed function',
            '  Coverage driver: yes (pcov or Xdebug in php.binary)',
            '  PHPStan: vendor/bin/phpstan analyse',
            '  Formatter: vendor/bin/php-cs-fixer fix {files} (detected)',
            '  Differential tests: 2 at a time (verification.jobs: 0, auto)',
        ]);
    }

    public function configuredJobsAreShownAsSet(): void
    {
        $header = new RunHeader('Optimizing.', 0, 0, [], null, false, false, 'none (commands.phpstan is null)', 'no formatter (commands.format: none)', 3, 3);

        $lines = $header->lines();

        Assert::same($lines[2], '  Rules: none');
        Assert::same($lines[7], '  Differential tests: 3 at a time (verification.jobs: 3)');
    }

    /**
     * @param non-empty-string|null $runner
     */
    private static function header(?string $runner, bool $selective, bool $driver): RunHeader
    {
        return new RunHeader(
            'Optimizing 2 file(s) of /p with 9 rule(s), PHP 8.4.1 (target 8.1), git: a commit per accepted step.',
            12,
            10,
            ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9'],
            $runner,
            $selective,
            $driver,
            'vendor/bin/phpstan analyse',
            'vendor/bin/php-cs-fixer fix {files} (detected)',
            2,
            0,
        );
    }
}
