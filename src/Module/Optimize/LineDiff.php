<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

/**
 * Line diff of two texts (Myers' O(ND) algorithm): the size of a change for the readability
 * thresholds and the unified diff of the patch.
 *
 * @internal
 */
final class LineDiff
{
    /** @var list<array{' '|'-'|'+', string}> */
    private array $edits;

    public function __construct(string $old, string $new)
    {
        $this->edits = self::compute(self::lines($old), self::lines($new));
    }

    /**
     * Changed lines: the larger of the deleted and the inserted ones (a modified line counts once).
     *
     * @return int<0, max>
     */
    public function changedLines(): int
    {
        $deleted = $inserted = 0;
        foreach ($this->edits as [$op]) {
            $op === '-' and ++$deleted;
            $op === '+' and ++$inserted;
        }

        return \max($deleted, $inserted);
    }

    /**
     * Hunks of a unified diff (without the `---`/`+++` header), `$context` lines around changes.
     */
    public function unified(int $context = 3): string
    {
        $out = '';
        $count = \count($this->edits);
        $changes = [];
        foreach ($this->edits as $i => [$op]) {
            $op === ' ' or $changes[] = $i;
        }

        $i = 0;
        while ($i < \count($changes)) {
            $start = \max(0, $changes[$i] - $context);
            $end = \min($count - 1, $changes[$i] + $context);
            while ($i + 1 < \count($changes) && $end + 1 >= $changes[$i + 1] - $context) {
                ++$i;
                $end = \min($count - 1, $changes[$i] + $context);
            }

            ++$i;
            [$oldStart, $newStart] = $this->position($start);
            $oldLength = $newLength = 0;
            $body = '';
            for ($j = $start; $j <= $end; ++$j) {
                [$op, $line] = $this->edits[$j];
                $op === '+' or ++$oldLength;
                $op === '-' or ++$newLength;
                $body .= $op . $line . "\n";
            }

            $out .= \sprintf("@@ -%d,%d +%d,%d @@\n", $oldLength === 0 ? $oldStart - 1 : $oldStart, $oldLength, $newLength === 0 ? $newStart - 1 : $newStart, $newLength) . $body;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = \explode("\n", $text);
        # A trailing newline does not start another line.
        \end($lines) === '' and \array_pop($lines);

        return $lines;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{' '|'-'|'+', string}>
     */
    private static function compute(array $a, array $b): array
    {
        # Common prefix and suffix first: optimization steps change a few lines of long files.
        $prefix = 0;
        $n = \count($a);
        $m = \count($b);
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }

        $suffix = 0;
        /** @psalm-suppress InvalidArrayOffset the indexes stay within the lists */
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            ++$suffix;
        }

        $edits = [];
        for ($i = 0; $i < $prefix; ++$i) {
            $edits[] = [' ', $a[$i]];
        }

        \array_push($edits, ...self::myers(\array_slice($a, $prefix, $n - $prefix - $suffix), \array_slice($b, $prefix, $m - $prefix - $suffix)));
        foreach (\array_slice($a, $n - $suffix) as $line) {
            $edits[] = [' ', $line];
        }

        return $edits;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{' '|'-'|'+', string}>
     */
    private static function myers(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);
        $max = $n + $m;
        /** @var array<int, int> $v */
        $v = [1 => 0];
        /** @var list<array<int, int>> $trace */
        $trace = [];
        for ($d = 0; $d <= $max; ++$d) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = $k === -$d || ($k !== $d && ($v[$k - 1] ?? 0) < ($v[$k + 1] ?? 0)) ? ($v[$k + 1] ?? 0) : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;
                /** @psalm-suppress InvalidArrayOffset $x and $y stay within the lists here */
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    ++$x;
                    ++$y;
                }

                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b, $n, $m);
                }
            }
        }

        return [];
    }

    /**
     * @param list<array<int, int>> $trace
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{' '|'-'|'+', string}>
     */
    private static function backtrack(array $trace, array $a, array $b, int $x, int $y): array
    {
        $edits = [];
        for ($d = \count($trace) - 1; $d >= 0; --$d) {
            $v = $trace[$d];
            $k = $x - $y;
            $prevK = $k === -$d || ($k !== $d && ($v[$k - 1] ?? 0) < ($v[$k + 1] ?? 0)) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) {
                $edits[] = [' ', $a[--$x]];
                --$y;
            }

            if ($d > 0) {
                $x === $prevX ? $edits[] = ['+', $b[--$y]] : $edits[] = ['-', $a[--$x]];
                $x = $prevX;
                $y = $prevY;
            }
        }

        return \array_reverse($edits);
    }

    /**
     * 1-based line numbers of the edit at `$index` in the old and the new text.
     *
     * @return array{int, int}
     */
    private function position(int $index): array
    {
        $old = $new = 1;
        for ($i = 0; $i < $index; ++$i) {
            $op = $this->edits[$i][0];
            $op === '+' or ++$old;
            $op === '-' or ++$new;
        }

        return [$old, $new];
    }
}
