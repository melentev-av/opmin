<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Page38
{
    private string $title = 'page';

    public function heading(): string
    {
        return \htmlspecialchars($this->title) . \nl2br($this->title) . \hash('crc32b', $this->title);
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Page38
{
    private string $title = 'page';

    public function heading(): string
    {
        $title = $this->title;
        return \htmlspecialchars($title) . \nl2br($title) . \hash('crc32b', $title);
    }
}

?>
