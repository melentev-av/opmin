<?php

namespace App\InArray7;

function local(string $host): bool
{
    $config = ['host' => $host];
    return $config['host'] === 'localhost' || $config['host'] === '127.0.0.1';
}

?>
-----
<?php

namespace App\InArray7;

function local(string $host): bool
{
    $config = ['host' => $host];
    return \in_array($config['host'], ['localhost', '127.0.0.1'], true);
}

?>
