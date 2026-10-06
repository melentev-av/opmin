<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Line coverage through Xdebug or pcov, when the orchestrator asks for a driver instead of the probes.
 *
 * @internal
 */
final class Coverage
{
    private static ?string $driver = null;

    /** @var list<string> */
    private static array $files = [];

    /**
     * @return list<string> Drivers loaded in this PHP.
     */
    public static function available(): array
    {
        $drivers = ['probes'];
        \function_exists('xdebug_start_code_coverage') and $drivers[] = 'xdebug';
        \function_exists('pcov\start') and $drivers[] = 'pcov';

        return $drivers;
    }

    /**
     * @param list<string> $files Files whose lines are reported.
     */
    public static function configure(?string $driver, array $files): void
    {
        $driver === null || \in_array($driver, self::available(), true)
            or throw new \RuntimeException("Coverage driver {$driver} is not loaded in PHP " . \PHP_VERSION);
        self::$driver = $driver === 'probes' ? null : $driver;
        self::$files = \array_values(\array_filter(\array_map('realpath', $files)));
    }

    public static function start(): void
    {
        if (self::$driver === 'xdebug') {
            xdebug_start_code_coverage((int) \constant('XDEBUG_CC_UNUSED') | (int) \constant('XDEBUG_CC_DEAD_CODE'));
        } elseif (self::$driver === 'pcov') {
            \pcov\clear();
            \pcov\start();
        }
    }

    /**
     * @return array<string, array{executed: list<int>, executable: list<int>}>|null Per file; null without a driver.
     */
    public static function stop(): ?array
    {
        if (self::$driver === null) {
            return null;
        }

        if (self::$driver === 'xdebug') {
            /** @var array<string, array<int, int>> $raw */
            $raw = xdebug_get_code_coverage();
            xdebug_stop_code_coverage(true);
        } else {
            \pcov\stop();
            /** @var array<string, array<int, int>> $raw */
            $raw = \pcov\collect(\pcov\inclusive, self::$files);
            \pcov\clear();
        }

        $result = [];
        foreach (self::$files as $file) {
            $executed = $executable = [];
            foreach ($raw[$file] ?? [] as $line => $hits) {
                # Xdebug: 1 executed, -1 not executed, -2 dead; pcov: 1 executed, -1 not executed.
                $hits === -2 or $executable[] = (int) $line;
                $hits > 0 and $executed[] = (int) $line;
            }

            $result[$file] = ['executed' => $executed, 'executable' => $executable];
        }

        return $result;
    }
}
