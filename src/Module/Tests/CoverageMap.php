<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

/**
 * Which tests execute which lines: from a coverage run of the project's tests. A function is
 * covered by the tests that execute any line of it.
 *
 * @internal
 */
final readonly class CoverageMap
{
    /**
     * @param array<non-empty-string, array<int, list<non-empty-string>>> $lines Absolute file => line => test ids.
     */
    public function __construct(
        public array $lines,
    ) {}

    /**
     * Tests that execute lines `$from`–`$to` of a file.
     *
     * @return list<non-empty-string>
     */
    public function tests(string $file, int $from, int $to): array
    {
        $real = \realpath($file);
        $file = $real === false ? $file : $real;
        $tests = [];
        foreach ($this->lines[$file] ?? [] as $line => $ids) {
            if ($line >= $from && $line <= $to) {
                foreach ($ids as $id) {
                    $tests[$id] = true;
                }
            }
        }

        $result = \array_map(strval(...), \array_keys($tests));
        \sort($result, \SORT_STRING);

        /** @var list<non-empty-string> */
        return $result;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
