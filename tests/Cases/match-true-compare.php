<?php

declare(strict_types=1);

/**
 * `match (true)` jumps to the arm (`JMPNZ`) and then to the end, `if` jumps past it on the condition
 * (`JMPZ`); on 8.5 `match` also keeps the comparison with `true` (`TYPE_CHECK`), on 8.1–8.4 the
 * optimizer drops it for a `bool` condition. Zend/zend_compile.c (zend_compile_match).
 * Rule: MatchTrueToIfRector.
 */

return [
    'rule' => 'Opmin\Rector\Rule\MatchTrueToIfRector',
    'before' => "function f(int \$x) { return match (true) { \$x < 0 => 'neg', \$x === 0 => 'zero', default => 'pos' }; }",
    'after' => "function f(int \$x) { if (\$x < 0) { return 'neg'; } if (\$x === 0) { return 'zero'; } return 'pos'; }",
    'expect' => [
        '8.1' => ['gone' => ['JMPNZ', 'JMPZNZ'], 'new' => ['JMPZ'], 'ops' => '-'],
        '8.2-8.4' => ['gone' => ['JMPNZ'], 'new' => ['JMPZ'], 'ops' => '-'],
        '8.5' => ['gone' => ['JMPNZ', 'TYPE_CHECK'], 'new' => ['JMPZ'], 'ops' => '-'],
    ],
];
