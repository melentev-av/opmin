<?php

namespace App\Clock;

function stamp(): string
{
    return time() . date('c');
}

?>
-----
<?php

namespace App\Clock;

function stamp(): string
{
    return time() . \date('c');
}

?>
