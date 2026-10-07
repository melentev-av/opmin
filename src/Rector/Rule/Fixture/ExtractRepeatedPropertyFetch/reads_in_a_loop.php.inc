<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Repo7
{
    public function save(int $i): void {}
}

final class Importer7
{
    public function __construct(private readonly Repo7 $repo) {}

    public function import(array $rows): void
    {
        $this->repo->save(0);
        foreach ($rows as $row) {
            $this->repo->save($row);
        }
    }
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Repo7
{
    public function save(int $i): void {}
}

final class Importer7
{
    public function __construct(private readonly Repo7 $repo) {}

    public function import(array $rows): void
    {
        $repo = $this->repo;
        $repo->save(0);
        foreach ($rows as $row) {
            $repo->save($row);
        }
    }
}

?>
