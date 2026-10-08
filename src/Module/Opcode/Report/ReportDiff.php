<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Report;

/**
 * Function-by-function comparison of two count reports (`ops_opt`).
 *
 * Opcode counts depend on the PHP version and the optimizer settings, so reports taken with
 * different ones are not compared at all. Another opmin version or `php.target` is compared with a
 * warning: the counts are comparable, but they may come from other rules.
 *
 * @internal
 */
final readonly class ReportDiff
{
    /**
     * @param list<array{key: non-empty-string, before: int, after: int, delta: int}> $better Biggest gain first.
     * @param list<array{key: non-empty-string, before: int, after: int, delta: int}> $worse Biggest loss first.
     * @param array<non-empty-string, int> $added Function => ops in the second report only.
     * @param array<non-empty-string, int> $removed Function => ops in the first report only.
     * @param list<non-empty-string> $warnings What differs between the runs besides the code.
     */
    private function __construct(
        public array $better,
        public array $worse,
        public array $added,
        public array $removed,
        public int $unchanged,
        public int $totalBefore,
        public int $totalAfter,
        public array $warnings = [],
    ) {}

    /**
     * @throws ReportException When the reports were taken with another PHP version or optimizer settings.
     */
    public static function compare(CountReport $before, CountReport $after): self
    {
        $before->php === $after->php or throw new ReportException(\sprintf(
            'The reports were taken with different PHP versions (%s and %s): opcode counts are not comparable. '
            . 'Count both with the same php.binary.',
            $before->php,
            $after->php,
        ));
        $before->optimizerHash === $after->optimizerHash or throw new ReportException(\sprintf(
            'The reports were taken with different optimizer settings (optimizer_hash %s and %s): opcode counts '
            . 'are not comparable. Count both with the same php.binary, Zend extensions and opmin version.',
            $before->optimizerHash,
            $after->optimizerHash,
        ));

        $warnings = [];
        $before->opmin === $after->opmin or $warnings[] = "The reports were taken with different opmin versions ({$before->opmin} and {$after->opmin}).";
        $before->phpTarget === $after->phpTarget or $warnings[] = \sprintf(
            'The reports were taken with different php.target (%s and %s).',
            $before->phpTarget ?? 'none',
            $after->phpTarget ?? 'none',
        );

        $better = $worse = $added = $removed = [];
        $unchanged = 0;
        foreach ($after->functions as $key => $function) {
            $old = $before->functions[$key] ?? null;
            if ($old === null) {
                $added[$key] = $function->opsOpt;
                continue;
            }

            $delta = $function->opsOpt - $old->opsOpt;
            $row = ['key' => $key, 'before' => $old->opsOpt, 'after' => $function->opsOpt, 'delta' => $delta];
            match (true) {
                $delta < 0 => $better[] = $row,
                $delta > 0 => $worse[] = $row,
                default => ++$unchanged,
            };
        }

        foreach ($before->functions as $key => $function) {
            isset($after->functions[$key]) or $removed[$key] = $function->opsOpt;
        }

        $order = static fn(array $a, array $b): int => [\abs((int) $b['delta']), (string) $a['key']] <=> [\abs((int) $a['delta']), (string) $b['key']];
        \usort($better, $order);
        \usort($worse, $order);

        return new self($better, $worse, $added, $removed, $unchanged, $before->opsOpt(), $after->opsOpt(), $warnings);
    }
}
