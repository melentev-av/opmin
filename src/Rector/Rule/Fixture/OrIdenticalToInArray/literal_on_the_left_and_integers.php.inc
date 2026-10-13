<?php

namespace App\InArray3;

function edge(int $i): bool
{
    return -1 === $i || $i === 0 || 1 === $i;
}

?>
-----
<?php

namespace App\InArray3;

function edge(int $i): bool
{
    return \in_array($i, [-1, 0, 1], true);
}

?>
