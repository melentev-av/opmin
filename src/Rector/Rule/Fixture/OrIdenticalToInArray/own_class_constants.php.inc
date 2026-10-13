<?php

namespace App\InArray5;

class Status
{
    public const DRAFT = 'draft';
    public const HIDDEN = 'hidden';

    public function closed(string $status): bool
    {
        return $status === self::DRAFT || $status === Status::HIDDEN;
    }
}

?>
-----
<?php

namespace App\InArray5;

class Status
{
    public const DRAFT = 'draft';
    public const HIDDEN = 'hidden';

    public function closed(string $status): bool
    {
        return \in_array($status, [self::DRAFT, Status::HIDDEN], true);
    }
}

?>
