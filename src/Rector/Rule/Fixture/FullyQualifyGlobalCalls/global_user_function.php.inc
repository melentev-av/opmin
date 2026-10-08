<?php

namespace {
    function opmin_fixture_helper(): int
    {
        return 1;
    }
}

namespace App\Fqn5 {
    function run(): int
    {
        return opmin_fixture_helper();
    }
}

?>
-----
<?php

namespace {
    function opmin_fixture_helper(): int
    {
        return 1;
    }
}

namespace App\Fqn5 {
    function run(): int
    {
        return \opmin_fixture_helper();
    }
}

?>
