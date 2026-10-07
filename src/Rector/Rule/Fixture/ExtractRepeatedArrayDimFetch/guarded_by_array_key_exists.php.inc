<?php

namespace App\Dim3;

function pair(array $row): array
{
    if (array_key_exists('user_id', $row)) {
        return [$row['user_id'], $row['user_id']];
    }

    return [];
}

?>
-----
<?php

namespace App\Dim3;

function pair(array $row): array
{
    if (array_key_exists('user_id', $row)) {
        $userId = $row['user_id'];
        return [$userId, $userId];
    }

    return [];
}

?>
