<?php

declare(strict_types=1);

/**
 * An interpolated string of two parts is one `FAST_CONCAT`, as `.`: no rope, nothing to gain.
 * Zend/zend_compile.c (zend_compile_encaps_list). Rule: ConcatToInterpolationRector (MIN_PARTS).
 */

return [
    'rule' => 'Opmin\Rector\Rule\ConcatToInterpolationRector',
    'before' => 'function f(string $a, string $b) { return $a . $b; }',
    'after' => 'function f(string $a, string $b) { return "{$a}{$b}"; }',
    'expect' => [
        '8.1-8.5' => ['ops' => '0'],
    ],
];
