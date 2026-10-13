<?php

namespace Opmin\Tests\Fixture\Pure;

function trap3(string $s, int $i, float $f)
{
    # A warning or deprecation on a valid-typed value runs the error handler.
    \chr($i);
    \ord($s);
    \pow($i, $i);
    \range($i, $i);
    \hexdec($s);
    \base_convert($s, 16, 2);
    \addcslashes($s, 'a..');
    \convert_uudecode($s);
    \str_getcsv($s);
    \unpack($s, $s);
    # An optional argument of a function known from the metadata.
    \htmlspecialchars($s, \ENT_QUOTES, 'foo');
    \wordwrap($s, $i);
    # Beyond the most arguments of a reviewed function.
    \trim($s, 'a..');
    \ucfirst($s, $s);
}

?>
