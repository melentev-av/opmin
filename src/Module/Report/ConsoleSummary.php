<?php

declare(strict_types=1);

namespace Opmin\Module\Report;

/**
 * The end of a run on the console (`optimize`, `llm:finish`): the functions that saved most, what was
 * rolled back by kind, and what to do next ({@see Advice}) — a few lines, the rest is in the report.
 *
 * @internal
 */
final class ConsoleSummary
{
    private const TOP = 5;

    /** Advice lines shown, the most useful first; the last-resort line comes after them. */
    private const MAX_ADVICE = 2;

    /**
     * @param array<string, mixed> $report The data of `report.json`.
     * @return list<string> Plain text, no console tags.
     */
    public static function lines(array $report): array
    {
        return [...self::top($report), ...self::rolledBack($report), ...self::advice($report)];
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private static function top(array $report): array
    {
        /** @var array<string, array<string, mixed>> $functions */
        $functions = \is_array($report['functions'] ?? null) ? $report['functions'] : [];
        if (\count($functions) <= 1) {
            return [];
        }

        \uksort($functions, static fn(string $a, string $b): int => [(int) ($functions[$b]['saved'] ?? 0), $a] <=> [(int) ($functions[$a]['saved'] ?? 0), $b]);
        $top = \array_slice($functions, 0, self::TOP, true);
        $width = 0;
        $counts = [];
        # Every count has one "→": byte lengths align them.
        $countWidth = 0;
        foreach ($top as $key => $function) {
            $width = \max($width, \min(60, \strlen((string) $key)));
            $count = \sprintf('%s → %s', (string) ($function['ops_before'] ?? '?'), (string) ($function['ops_after'] ?? '?'));
            $countWidth = \max($countWidth, \strlen($count));
            $counts[] = $count;
        }

        $lines = [\count($functions) > self::TOP ? \sprintf('Saved most (%d of %d functions):', self::TOP, \count($functions)) : 'Saved most:'];
        $i = 0;
        foreach ($top as $key => $function) {
            $lines[] = \sprintf(
                '  %s  %s  %s',
                \str_pad((string) $key, $width),
                \str_pad($counts[$i++], $countWidth),
                (string) ($function['status'] ?? ''),
            );
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private static function rolledBack(array $report): array
    {
        $counts = [];
        /** @var mixed $entry */
        foreach (\is_array($report['rejected'] ?? null) ? $report['rejected'] : [] as $entry) {
            $kind = \is_array($entry) ? RejectionKind::tryFrom((string) ($entry['kind'] ?? '')) : null;
            $kind === null or $counts[$kind->value] = ($counts[$kind->value] ?? 0) + 1;
        }

        if ($counts === []) {
            return [];
        }

        $parts = [];
        foreach (RejectionKind::cases() as $kind) {
            isset($counts[$kind->value]) and $parts[] = "{$counts[$kind->value]} {$kind->title()}";
        }

        return [\sprintf('Rolled back %d: %s.', \array_sum($counts), \implode(', ', $parts))];
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private static function advice(array $report): array
    {
        $lines = [];
        $last = [];
        $more = 0;
        foreach (Advice::fromReport($report) as $advice) {
            $line = \sprintf('%s (%d)', $advice->text, $advice->count);
            if ($advice->cause === Advice::LAST_RESORT) {
                $last[] = '  ! ' . $line;
            } elseif (\count($lines) < self::MAX_ADVICE) {
                $lines[] = '  - ' . $line;
            } else {
                ++$more;
            }
        }

        $lines = [...$lines, ...$last];

        return $lines === [] ? [] : [$more === 0 ? 'What to do next:' : "What to do next ({$more} more in the report):", ...$lines];
    }
}
