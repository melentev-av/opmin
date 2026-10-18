<?php

declare(strict_types=1);

/**
 * `?->` is `JMP_NULL` and the fetch instead of a comparison with `null` and a jump.
 * Rule: NullCheckTernaryToNullsafeRector (off by default: it costs more with the JIT).
 */

return [
    'rule' => 'Opmin\Rector\Rule\NullCheckTernaryToNullsafeRector',
    'before' => 'function f(?\stdClass $o) { return $o !== null ? $o->v : null; }',
    'after' => 'function f(?\stdClass $o) { return $o?->v; }',
    'expect' => [
        '8.1-8.5' => ['gone' => ['JMPZ', 'TYPE_CHECK'], 'new' => ['JMP_NULL'], 'ops' => '-'],
    ],
];
