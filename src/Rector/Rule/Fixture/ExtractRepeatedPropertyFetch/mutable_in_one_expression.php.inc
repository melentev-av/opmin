<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Counter4
{
    private int $n = 0;

    public function square(): int
    {
        return $this->n * $this->n + 1;
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Counter4
{
    private int $n = 0;

    public function square(): int
    {
        $n = $this->n;
        return $n * $n + 1;
    }
}

?>
