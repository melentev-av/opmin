<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Config2
{
    public function __construct(public readonly string $host, public readonly int $port) {}
}

final class Client2
{
    private string $host;
    private int $port;

    public function __construct(private readonly Config2 $config)
    {
        $this->host = $this->config->host;
        $this->port = $this->config->port;
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Config2
{
    public function __construct(public readonly string $host, public readonly int $port) {}
}

final class Client2
{
    private string $host;
    private int $port;

    public function __construct(private readonly Config2 $config)
    {
        $config2 = $this->config;
        $this->host = $config2->host;
        $this->port = $config2->port;
    }
}

?>
