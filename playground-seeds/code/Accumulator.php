<?php

declare(strict_types=1);

namespace PlaygroundSeeds;

/**
 * Collects lines: the collaborator of RuleWins.
 */
final class Accumulator
{
    /** @var list<string> */
    public array $lines = [];

    public function add(string $line): void
    {
        $this->lines[] = $line;
    }
}
