<?php

function pure2(string $s)
{
    strtolower($s);
    htmlspecialchars($s);
}

?>
-----
<?php

function pure2(string $s)
{
    $pure = strtolower($s);
    $pure = htmlspecialchars($s);
}

?>
