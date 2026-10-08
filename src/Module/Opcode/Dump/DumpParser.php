<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Dump;

/**
 * Parses the OPcache optimizer dump (`opcache.opt_debug_level`) of one file.
 *
 * Format (PHP 8.1–8.5, see tests/Fixtures/Dumps/): a header line `<name>:` at column 0, then
 * `     ; ` lines with `(lines=N, args=N, vars=N, tmps=N)`, the phase, `<file>:<start>-<end>` and
 * sometimes type info, then the opcodes numbered from `0000` (`0003 T1 = IS_SMALLER int(1) CV0($a)`).
 * Other column-0 lines (`LIVE RANGES:`, `EXCEPTION TABLE:`, PHP warnings) are not headers.
 *
 * PHP 8.1–8.4 print string operands unescaped (8.5 escapes them): a literal with a newline continues
 * on the next lines, and its text can look like anything, an opcode line or a header included. Hence:
 *  - opcode lines are recognized by their numbers and the block end by having them all (see
 *    {@see self::opcodes()}); everything else between them is the continuation of a string;
 *  - a header counts only with all its meta lines (stats, phase, location); a fake block forged
 *    entirely inside a string ends up as an extra block that the matcher rejects — a failure,
 *    never a wrong count.
 * The count itself always comes from `lines=N`. Known limit of the histogram on PHP 8.1–8.4: a
 * literal that imitates the line of its own opcode (`0003 … string("x\n0003 RETURN …")`) is not
 * told apart from it, and that opcode may be named wrong.
 *
 * The parser is strict: a block with fewer numbered opcode lines than `lines=N` is an error, not a
 * guess — a wrong count is worse than no count.
 *
 * @internal
 */
final class DumpParser
{
    private const STATS = '/^\s+; \(lines=(\d+), args=(\d+), vars=(\d+), tmps=(\d+)\)$/';
    private const LOCATION = '/^\s+; (.+):(\d+)-(\d+)$/';
    private const OPCODE = '/^\d{4,} (?:\S+ = )?([A-Z][A-Z0-9_]*)\b/';

    /**
     * @return list<DumpBlock> Blocks in dump order, both phases.
     * @throws DumpParseException
     */
    public function parse(string $dump): array
    {
        $lines = \explode("\n", $dump);
        $blocks = [];
        $count = \count($lines);
        $i = 0;
        while ($i < $count) {
            $line = $lines[$i++];
            if ($line === '' || $line[0] === ' ' || !\str_ends_with($line, ':')
                || \preg_match(self::STATS, $lines[$i] ?? '', $stats) !== 1
            ) {
                continue;
            }

            $phase = null;
            $location = null;
            for ($j = $i + 1; $j < $count && \str_starts_with($lines[$j], '     ; '); ++$j) {
                $phase ??= Phase::tryFrom(\trim(\substr($lines[$j], 7), ' ()'));
                \preg_match(self::LOCATION, $lines[$j], $m) === 1 and $location = $m;
            }

            if ($phase === null || $location === null) {
                continue;
            }

            # Names of anonymous classes contain a NUL byte followed by the file path.
            $name = \explode("\0", \substr($line, 0, -1), 2)[0];
            $ops = (int) $stats[1];
            [$opcodes, $i] = $this->opcodes($lines, $j, $ops, $name, $phase);
            $listing = \implode("\n", \array_slice($lines, $j, $i - $j));

            $blocks[] = new DumpBlock(
                name: $name,
                phase: $phase,
                ops: \max(0, $ops),
                args: (int) $stats[2],
                vars: (int) $stats[3],
                tmps: (int) $stats[4],
                file: $location[1],
                lineStart: \max(0, (int) $location[2]),
                lineEnd: (int) $location[3],
                opcodes: $opcodes,
                listing: $listing,
            );
        }

        return $blocks;
    }

    /**
     * Picks the opcode lines of a block whose first opcode is at line $start.
     *
     * Opcodes are numbered `0000`…`N-1`; the block ends at the next header, `LIVE RANGES:` or
     * `EXCEPTION TABLE:`. A string literal can contain such lines too, so the end is the first of
     * these boundaries before which all N numbers are found. Within it each number is taken at its
     * last occurrence before the next number, going from the end: a string imitating opcode lines
     * lies inside the literal of an earlier opcode, so the real line with the same number comes
     * later and wins.
     *
     * @param list<string> $lines
     * @return array{array<non-empty-string, positive-int>, int} Histogram sorted by name, end of the block.
     * @throws DumpParseException
     */
    private function opcodes(array $lines, int $start, int $ops, string $name, Phase $phase): array
    {
        $count = \count($lines);
        $positions = [];
        for ($end = $start; $end <= $count; ++$end) {
            if ($end < $count && !$this->endsBlock($lines, $end)) {
                continue;
            }

            $positions = $this->numbered($lines, $start, $end, $ops);
            if (\count($positions) === $ops) {
                break;
            }
        }

        \count($positions) === $ops or throw new DumpParseException(\sprintf(
            'Block `%s` (%s): header says lines=%d, but %d opcode lines follow.',
            $name,
            $phase->value,
            $ops,
            \count($positions),
        ));

        /** @var array<non-empty-string, positive-int> $opcodes */
        $opcodes = [];
        foreach ($positions as $p) {
            \preg_match(self::OPCODE, $lines[$p], $op) === 1 or throw new DumpParseException(
                "Block `{$name}` ({$phase->value}): cannot read the opcode in `{$lines[$p]}`.",
            );
            /** @var non-empty-string $opcode */
            $opcode = $op[1];
            $opcodes[$opcode] = ($opcodes[$opcode] ?? 0) + 1;
        }

        \ksort($opcodes);

        return [$opcodes, \max($positions === [] ? $start : \max($positions) + 1, $start)];
    }

    /**
     * Lines of the numbered opcodes in [$start, $end), see {@see self::opcodes()}.
     *
     * @param list<string> $lines
     * @return array<int, int> Number => line; fewer than $ops when some are missing.
     */
    private function numbered(array $lines, int $start, int $end, int $ops): array
    {
        $positions = [];
        $pos = $end;
        for ($n = $ops - 1; $n >= 0; --$n) {
            $prefix = \sprintf('%04d ', $n);
            for ($p = $pos - 1; $p >= $start && !\str_starts_with($lines[$p], $prefix); --$p);
            $p >= $start and $positions[$n] = $pos = $p;
        }

        # The first opcode follows the meta lines directly.
        if (($positions[0] ?? null) !== $start) {
            unset($positions[0]);
        }

        \ksort($positions);

        return $positions;
    }

    /**
     * @param list<string> $lines
     */
    private function endsBlock(array $lines, int $i): bool
    {
        $line = $lines[$i];

        return $line === 'LIVE RANGES:' || $line === 'EXCEPTION TABLE:'
            || ($line !== '' && $line[0] !== ' ' && \str_ends_with($line, ':')
                && \preg_match(self::STATS, $lines[$i + 1] ?? '') === 1);
    }
}
