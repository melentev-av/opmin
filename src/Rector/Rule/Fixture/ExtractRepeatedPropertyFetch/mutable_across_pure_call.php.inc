<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Label28
{
    private string $text = 'label';

    public function width(): int
    {
        return \strlen($this->text) + \substr_count($this->text, ' ');
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Label28
{
    private string $text = 'label';

    public function width(): int
    {
        $text = $this->text;
        return \strlen($text) + \substr_count($text, ' ');
    }
}

?>
