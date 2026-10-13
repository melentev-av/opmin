<?php

function filterStates(array $states): array
{
    return array_filter($states, static fn(string $s): bool => $s === 'a' || $s === 'b');
}

?>
-----
<?php

function filterStates(array $states): array
{
    return array_filter($states, static fn(string $s): bool => \in_array($s, ['a', 'b'], true));
}

?>
