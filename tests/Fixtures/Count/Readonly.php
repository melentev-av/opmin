<?php

// Fixture for opcode counting (PHP 8.2+): a readonly class.

declare(strict_types=1);

namespace Fixture\Count;

final readonly class Money
{
    public function __construct(
        public int $amount,
    ) {}

    public function add(int $x): self
    {
        return new self($this->amount + $x);
    }
}
