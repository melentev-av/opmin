<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Positive cases of opmin's own rules: they must fire here and save opcodes.
 */
final class RuleWins
{
    public function __construct(
        private readonly Accumulator $out = new Accumulator(),
    ) {}

    /**
     * A readonly property read across method calls and in a loop.
     *
     * @param list<int> $rows
     * @return list<string>
     */
    public function report(array $rows): array
    {
        $this->out->add('start');
        foreach ($rows as $row) {
            $this->out->add((string) $row);
        }
        $this->out->add('end');

        return $this->out->lines;
    }

    /**
     * An array with a proven shape.
     */
    public function address(string $host, int $port): string
    {
        $c = ['host' => $host, 'port' => $port, 'scheme' => 'https'];

        return $c['scheme'] . '://' . $c['host'] . ':' . $c['port'] . '/' . $c['host'] . '/' . $c['scheme'];
    }

    /**
     * Keys proven by isset().
     *
     * @param array<string, int> $row
     */
    public function lineTotal(array $row): int
    {
        if (isset($row['qty'], $row['price'])) {
            return $row['qty'] * $row['price'] + $row['qty'] * $row['qty'];
        }

        return 0;
    }

    /**
     * A loop-invariant count().
     *
     * @param list<int> $xs
     */
    public function total(array $xs): int
    {
        $s = 0;
        for ($i = 0; $i < count($xs); $i++) {
            $s += $xs[$i];
        }

        return $s;
    }
}
