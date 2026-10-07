<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node\Expr;

/**
 * Which repeated expression an extract rule moves into a variable: `$x->foo` or `$a['k']`.
 *
 * @internal
 */
interface ReadSpec
{
    /**
     * The key and the base variable of an expression of the extracted kind, null for other expressions.
     *
     * @return array{non-empty-string, non-empty-string}|null [key, base variable without `$`]
     */
    public function match(Expr $expr): ?array;

    /**
     * Whether the first read may be extracted at all: types, magic methods, initialization.
     */
    public function safe(Expr $read): bool;

    /**
     * Whether the value cannot change between reads except through a write to the expression or
     * its base (a readonly property): then calls between the reads are allowed.
     */
    public function stable(Expr $read): bool;

    /**
     * Name for the variable, without `$` (the caller makes it unique).
     *
     * @return non-empty-string
     */
    public function name(Expr $read): string;
}
