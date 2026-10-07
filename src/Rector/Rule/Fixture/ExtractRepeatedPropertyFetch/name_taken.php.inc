<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Logger6
{
    public function info(string $m): void {}
}

final class Service6
{
    public function __construct(private readonly Logger6 $logger) {}

    public function run(string $logger): void
    {
        $this->logger->info($logger);
        $this->logger->info('b');
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Logger6
{
    public function info(string $m): void {}
}

final class Service6
{
    public function __construct(private readonly Logger6 $logger) {}

    public function run(string $logger): void
    {
        $logger2 = $this->logger;
        $logger2->info($logger);
        $logger2->info('b');
    }
}

?>
