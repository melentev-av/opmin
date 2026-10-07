<?php

namespace App\Dim5;

function twice(\ArrayAccess $bag): mixed
{
    return [$bag['k'], $bag['k']];
}

?>
