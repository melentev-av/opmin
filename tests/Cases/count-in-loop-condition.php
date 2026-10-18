<?php

declare(strict_types=1);

/**
 * `\count()` in a loop condition runs on every iteration; hoisted, it runs once. The static count does
 * not drop, the executed one does. Rule: HoistLoopInvariantCountRector (EXECUTED_GAIN).
 */

return [
    'rule' => 'Opmin\Rector\Rule\HoistLoopInvariantCountRector',
    'before' => 'function f(array $a) { $t = 0; for ($i = 0; $i < \count($a); $i++) { $t += $a[$i]; } return $t; }',
    'after' => 'function f(array $a) { $t = 0; for ($i = 0, $n = \count($a); $i < $n; $i++) { $t += $a[$i]; } return $t; }',
    'expect' => [
        '8.1-8.5' => ['ops' => '0'],
    ],
];
