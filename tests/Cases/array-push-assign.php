<?php

declare(strict_types=1);

/**
 * `$a[] = $v` is one `ASSIGN_DIM`, `\array_push()` a call. Rule: ArrayPushToAssignRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\ArrayPushToAssignRector',
    'before' => 'function f(array $a, int $v) { \array_push($a, $v); return $a; }',
    'after' => 'function f(array $a, int $v) { $a[] = $v; return $a; }',
    'expect' => [
        '8.1-8.5' => ['gone' => ['DO_ICALL', 'INIT_FCALL', 'SEND_REF', 'SEND_VAR'], 'new' => ['ASSIGN_DIM', 'OP_DATA'], 'ops' => '-'],
    ],
];
