<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StLogger { public function info(string $m): void; }

final class StService
{
    private string $name;

    public function __construct(private StLogger $logger, string $name)
    {
        $this->name = $name;
    }

    public function run(): string
    {
        $a = $this->name;
        $this->logger->info('x');
        return $a . $this->name;
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetchStable;

interface StLogger { public function info(string $m): void; }

final class StService
{
    private string $name;

    public function __construct(private StLogger $logger, string $name)
    {
        $this->name = $name;
    }

    public function run(): string
    {
        $name = $this->name;
        $a = $name;
        $this->logger->info('x');
        return $a . $name;
    }
}

?>
