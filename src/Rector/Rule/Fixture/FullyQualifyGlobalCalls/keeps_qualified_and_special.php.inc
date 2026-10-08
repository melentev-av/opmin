<?php

namespace App\Fqn3;

function check($v): bool
{
    return \is_int($v) || $v === null || $v === true || $v === FALSE;
}

?>
