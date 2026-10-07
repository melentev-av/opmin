<?php

namespace App\Dim1;

function address(string $host, int $port): string
{
    $config = ['host' => $host, 'port' => $port];
    return $config['host'] . ':' . $config['port'] . ' (' . $config['host'] . ')';
}

?>
-----
<?php

namespace App\Dim1;

function address(string $host, int $port): string
{
    $config = ['host' => $host, 'port' => $port];
    $host2 = $config['host'];
    return $host2 . ':' . $config['port'] . ' (' . $host2 . ')';
}

?>
