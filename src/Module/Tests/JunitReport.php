<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

/**
 * Reads a JUnit XML report (PHPUnit, Pest, Testo `--log-junit`), see {@see XmlElements}.
 *
 * @internal
 */
final class JunitReport
{
    /**
     * @return array{tests: non-negative-int, failed: list<non-empty-string>, seconds: float}|null Null when the file is missing or not JUnit.
     */
    public static function read(string $path): ?array
    {
        $xml = XmlElements::document($path, 'testsuites');
        if ($xml === null) {
            return null;
        }

        $tests = 0;
        $failed = [];
        $seconds = 0.0;
        foreach (XmlElements::find($xml, 'testcase') as ['attributes' => $case, 'body' => $body]) {
            ++$tests;
            $seconds += (float) ($case['time'] ?? 0);
            if (\preg_match('/<(?:failure|error)[\s>\/]/', $body) === 1) {
                $class = $case['class'] ?? '';
                $class === '' and $class = \str_replace('.', '\\', $case['classname'] ?? '');
                $id = ($class === '' ? '' : $class . '::') . ($case['name'] ?? '');
                $id === '' or $failed[] = $id;
            }
        }

        return ['tests' => $tests, 'failed' => $failed, 'seconds' => $seconds];
    }
}
