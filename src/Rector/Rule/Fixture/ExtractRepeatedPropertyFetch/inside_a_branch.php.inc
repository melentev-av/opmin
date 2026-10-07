<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Logger5
{
    public function info(string $m): void {}
}

final class Service5
{
    public function __construct(private readonly Logger5 $logger) {}

    public function run(bool $verbose): void
    {
        if ($verbose) {
            $this->logger->info('a');
            $this->logger->info('b');
        }
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Logger5
{
    public function info(string $m): void {}
}

final class Service5
{
    public function __construct(private readonly Logger5 $logger) {}

    public function run(bool $verbose): void
    {
        if ($verbose) {
            $logger = $this->logger;
            $logger->info('a');
            $logger->info('b');
        }
    }
}

?>
