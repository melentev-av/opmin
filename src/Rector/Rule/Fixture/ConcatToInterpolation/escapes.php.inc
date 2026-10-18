<?php

namespace App\Interpolate2;

function quotes(string $a, string $b): string
{
    return $a . ' "quoted" ' . $b . '!';
}

function dollarsAndBraces(string $a, string $b): string
{
    return $a . ' costs $5 {' . $b . '}';
}

function backslashes(string $a, string $b): string
{
    return $a . '\n is not a newline, \\ is a backslash ' . $b . "\t";
}

function emptyLiterals(string $a, string $b): string
{
    return $a . '' . $b . '' . '-';
}

?>
-----
<?php

namespace App\Interpolate2;

function quotes(string $a, string $b): string
{
    return "{$a} \"quoted\" {$b}!";
}

function dollarsAndBraces(string $a, string $b): string
{
    return "{$a} costs \$5 {{$b}}";
}

function backslashes(string $a, string $b): string
{
    return "{$a}\\n is not a newline, \\ is a backslash {$b}\t";
}

function emptyLiterals(string $a, string $b): string
{
    return "{$a}{$b}-";
}

?>
