<?php

namespace App\InArray1;

function isVowel(string $s): bool
{
    return $s === 'a' || $s === 'e' || $s === 'i';
}

?>
-----
<?php

namespace App\InArray1;

function isVowel(string $s): bool
{
    return \in_array($s, ['a', 'e', 'i'], true);
}

?>
