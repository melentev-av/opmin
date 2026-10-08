<?php

declare(strict_types=1);

use PlaygroundSeeds\Traps;

test('mode', function () {
    expect((new Traps())->mode('legacy-v1-compat'))->toBe('legacy')
        ->and((new Traps())->mode('v2'))->toBe('modern');
});

test('loose zero', function () {
    expect((new Traps())->isZero('0'))->toBeTrue()
        ->and((new Traps())->isZero('a'))->toBeFalse();
});
