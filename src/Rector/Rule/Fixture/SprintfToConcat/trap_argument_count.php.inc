<?php

namespace App\Sprintf13;

function tooFew(string $s): string
{
    # ArgumentCountError.
    return \sprintf('%s-%s', $s);
}

function tooMany(string $s): string
{
    # The extra argument is evaluated and ignored.
    return \sprintf('%s-%s', $s, $s, \strtoupper($s));
}

?>
