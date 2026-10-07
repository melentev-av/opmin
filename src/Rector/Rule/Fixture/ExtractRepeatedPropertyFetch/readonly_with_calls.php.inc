<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Bar1
{
    public function bar(): void {}
    public function baz(): void {}
}

final class ReadonlyWithCalls
{
    public function __construct(private readonly ?Bar1 $foo) {}

    public function run(): void
    {
        if ($this->foo === null) {
            return;
        }
        $this->foo->bar();
        $this->foo->baz();
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Bar1
{
    public function bar(): void {}
    public function baz(): void {}
}

final class ReadonlyWithCalls
{
    public function __construct(private readonly ?Bar1 $foo) {}

    public function run(): void
    {
        $foo = $this->foo;
        if ($foo === null) {
            return;
        }
        $foo->bar();
        $foo->baz();
    }
}

?>
