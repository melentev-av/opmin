<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Coverage;

use PhpParser\Node\Stmt;

/**
 * Code no input reaches: a range of the file, or the path past an `if` without `else` that is
 * always taken (the `else` the {@see Instrumenter} adds).
 *
 * @internal
 */
final readonly class Dead
{
    /**
     * @param int $from First byte (inclusive).
     * @param int $to Last byte (inclusive).
     */
    public function __construct(
        public int $from,
        public int $to,
        public int $startLine,
        public int $endLine,
        public ?Stmt\If_ $missingElseOf = null,
    ) {}

    public function contains(int $position): bool
    {
        return $this->missingElseOf === null && $position >= $this->from && $position <= $this->to;
    }
}
