<?php

declare(strict_types=1);

/**
 * An interpolated string of three and more parts is a rope (`ROPE_INIT`, `ROPE_ADD`, `ROPE_END`): an
 * opcode more than the chain of `.`, but one allocation. Zend/zend_compile.c (zend_compile_encaps_list).
 * Rule: ConcatToInterpolationRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\ConcatToInterpolationRector',
    'before' => "function f(string \$a, string \$b, string \$c) { return \$a . '-' . \$b . '-' . \$c; }",
    'after' => 'function f(string $a, string $b, string $c) { return "{$a}-{$b}-{$c}"; }',
    'expect' => [
        '8.1-8.5' => ['gone' => ['FAST_CONCAT'], 'new' => ['ROPE_ADD', 'ROPE_END', 'ROPE_INIT'], 'ops' => '+'],
    ],
];
