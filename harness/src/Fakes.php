<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Deterministic time and randomness for the analyzed code (brief 2.5).
 *
 * Unqualified calls in a namespace resolve to a function of that namespace first, so the harness
 * declares `App\time()`, `App\random_int()`… that return fixed or seeded values (the trick of
 * php-mock and ClockMock). A namespace that declares such a function itself keeps it. `\time()`
 * and calls from the global namespace are not faked: the determinism check catches them.
 * Before every call the clock is reset and `mt_srand()` seeds the rest (`rand`, `shuffle`,
 * `array_rand`, `str_shuffle`); Carbon and Symfony Clock get the same fixed time.
 *
 * @internal
 */
final class Fakes
{
    /** Functions declared in every faked namespace. */
    private const FUNCTIONS = [
        'time', 'microtime', 'hrtime', 'date', 'gmdate', 'mktime', 'gmmktime', 'strtotime', 'getdate', 'localtime',
        'idate', 'sleep', 'usleep', 'random_int', 'random_bytes', 'uniqid', 'lcg_value',
    ];

    private static float $start = 1700000000.0;
    private static int $seed = 42;

    /** Current fake time in microseconds. */
    private static int $now = 0;

    private static int $uniqid = 0;

    /**
     * @param list<string> $namespaces
     */
    public static function install(array $namespaces, float $time, int $seed): void
    {
        self::$start = $time;
        self::$seed = $seed;
        foreach ($namespaces as $namespace) {
            $namespace = \trim($namespace, '\\');
            if ($namespace === '' || \preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*$/', $namespace) !== 1) {
                continue;
            }

            foreach (self::FUNCTIONS as $function) {
                \function_exists("{$namespace}\\{$function}") or eval(
                    "namespace {$namespace}; function {$function}(...\$a) { return \\Opmin\\Harness\\Fakes::{$function}(...\$a); }"
                );
            }
        }

        self::reset();
    }

    public static function reset(): void
    {
        self::$now = (int) \round(self::$start * 1_000_000.0);
        self::$uniqid = 0;
        \mt_srand(self::$seed);
        if (\class_exists('Carbon\Carbon', false) && \method_exists('Carbon\Carbon', 'setTestNow')) {
            /** @psalm-suppress MixedMethodCall */
            \call_user_func(['Carbon\Carbon', 'setTestNow'], \call_user_func(['Carbon\Carbon', 'createFromTimestampUTC'], self::$start));
        }

        if (\class_exists('Carbon\CarbonImmutable', false) && \method_exists('Carbon\CarbonImmutable', 'setTestNow')) {
            \call_user_func(['Carbon\CarbonImmutable', 'setTestNow'], \call_user_func(['Carbon\CarbonImmutable', 'createFromTimestampUTC'], self::$start));
        }

        $clock = 'Symfony\Component\Clock\MockClock';
        if (\class_exists('Symfony\Component\Clock\Clock', false) && \class_exists($clock)) {
            /** @psalm-suppress MixedMethodCall */
            \call_user_func(['Symfony\Component\Clock\Clock', 'set'], new $clock((new \DateTimeImmutable())->setTimestamp((int) self::$start)));
        }
    }

    public static function time(): int
    {
        return \intdiv(self::$now, 1_000_000);
    }

    public static function microtime(bool $asFloat = false): string|float
    {
        return $asFloat
            ? self::$now / 1_000_000
            : \sprintf('%.8F %d', (self::$now % 1_000_000) / 1_000_000, \intdiv(self::$now, 1_000_000));
    }

    /**
     * @return array{int, int}|int
     */
    public static function hrtime(bool $asNumber = false): array|int
    {
        $ns = self::$now * 1000;

        return $asNumber ? $ns : [\intdiv($ns, 1_000_000_000), $ns % 1_000_000_000];
    }

    public static function date(string $format, ?int $timestamp = null): string
    {
        return \date($format, $timestamp ?? self::time());
    }

    public static function gmdate(string $format, ?int $timestamp = null): string
    {
        return \gmdate($format, $timestamp ?? self::time());
    }

    public static function idate(string $format, ?int $timestamp = null): int|false
    {
        return \idate($format, $timestamp ?? self::time());
    }

    public static function mktime(?int $hour = null, ?int $minute = null, ?int $second = null, ?int $month = null, ?int $day = null, ?int $year = null): int|false
    {
        $now = self::time();

        return \mktime(
            $hour ?? (int) \date('G', $now),
            $minute ?? (int) \date('i', $now),
            $second ?? (int) \date('s', $now),
            $month ?? (int) \date('n', $now),
            $day ?? (int) \date('j', $now),
            $year ?? (int) \date('Y', $now),
        );
    }

    public static function gmmktime(?int $hour = null, ?int $minute = null, ?int $second = null, ?int $month = null, ?int $day = null, ?int $year = null): int|false
    {
        $now = self::time();

        return \gmmktime(
            $hour ?? (int) \gmdate('G', $now),
            $minute ?? (int) \gmdate('i', $now),
            $second ?? (int) \gmdate('s', $now),
            $month ?? (int) \gmdate('n', $now),
            $day ?? (int) \gmdate('j', $now),
            $year ?? (int) \gmdate('Y', $now),
        );
    }

    public static function strtotime(string $datetime, ?int $baseTimestamp = null): int|false
    {
        return \strtotime($datetime, $baseTimestamp ?? self::time());
    }

    /**
     * @return array<array-key, int|string>
     */
    public static function getdate(?int $timestamp = null): array
    {
        return \getdate($timestamp ?? self::time());
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function localtime(?int $timestamp = null, bool $associative = false): array
    {
        return \localtime($timestamp ?? self::time(), $associative);
    }

    public static function sleep(int $seconds): int
    {
        self::$now += \max(0, $seconds) * 1_000_000;

        return 0;
    }

    public static function usleep(int $microseconds): void
    {
        self::$now += \max(0, $microseconds);
    }

    public static function random_int(int $min, int $max): int
    {
        $min <= $max or throw new \ValueError('random_int(): Argument #1 ($min) must be less than or equal to argument #2 ($max)');

        return \mt_rand($min, $max);
    }

    public static function random_bytes(int $length): string
    {
        $length >= 1 or throw new \ValueError('random_bytes(): Argument #1 ($length) must be greater than 0');
        $bytes = '';
        for ($i = 0; $i < $length; ++$i) {
            $bytes .= \chr(\mt_rand(0, 255));
        }

        return $bytes;
    }

    public static function uniqid(string $prefix = '', bool $moreEntropy = false): string
    {
        $id = $prefix . \sprintf('%08x%05x', self::time(), (self::$now + self::$uniqid++) % 0x100000);

        return $moreEntropy ? $id . \sprintf('.%08d', \mt_rand(0, 99_999_999)) : $id;
    }

    public static function lcg_value(): float
    {
        return \mt_rand() / (\mt_getrandmax() + 1);
    }
}
