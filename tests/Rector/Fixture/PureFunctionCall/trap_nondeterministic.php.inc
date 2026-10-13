<?php

namespace Opmin\Tests\Fixture\Pure;

function trap1(string $s, int $i)
{
    # No side effects, but another result on the next call.
    \time();
    \microtime(true);
    \hrtime(true);
    \mt_rand($i, $i);
    \random_int($i, $i);
    \random_bytes($i);
    \uniqid();
    \lcg_value();
    \date('Y');
    \str_shuffle($s);
    \memory_get_usage();
    \getmypid();
    \getenv($s);
    \spl_object_id(new \stdClass());
}

?>
