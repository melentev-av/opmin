<?php

namespace Opmin\Tests\Fixture\Pure;

use function Opmin\Tests\Fixture\Pure\Shadow\htmlspecialchars as escape;

function str_word_count(string $s): int
{
    return 0;
}

function trap5(string $s)
{
    # An unqualified name in a namespace may resolve to a namespaced function at run time.
    strtolower($s);
    str_word_count($s);
    escape($s);
    \Opmin\Tests\Fixture\Pure\str_word_count($s);
    # Objects and resources are not results to share; newer functions may be polyfills.
    \hash_init('md5');
    \str_increment($s);
    \json_validate($s);
    # An optional extension may be a polyfill.
    \mb_strlen($s);
    \ctype_digit($s);
}

?>
