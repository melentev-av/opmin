<?php

namespace App\InArray6;

final class Order
{
    public function __construct(private readonly string $state) {}

    public function open(): bool
    {
        return $this->state === 'new' || $this->state === 'paid';
    }
}

?>
-----
<?php

namespace App\InArray6;

final class Order
{
    public function __construct(private readonly string $state) {}

    public function open(): bool
    {
        return \in_array($this->state, ['new', 'paid'], true);
    }
}

?>
