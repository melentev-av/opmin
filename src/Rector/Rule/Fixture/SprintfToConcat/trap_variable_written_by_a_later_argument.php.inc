<?php

namespace App\Sprintf18;

function increment(int $i): string
{
    # `.` reads the CV $i after $i++ ran: "21" instead of "12".
    return \sprintf('%s%s', $i, $i++);
}

function assigned(string $s): string
{
    return \sprintf('%s-%s', $s, $s = 'x');
}

function byReference(string $s): string
{
    return \sprintf('%s-%s', $s, change($s));
}

function referenced(string $s): string
{
    $ref = &$s;

    return \sprintf('%s-%s', $s, change($ref));
}

function change(string &$s): string
{
    $s = 'changed';

    return 'x';
}

?>
