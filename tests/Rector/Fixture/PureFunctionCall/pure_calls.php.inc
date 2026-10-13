<?php

namespace Opmin\Tests\Fixture\Pure;

function pure1(string $s, int $i, float $f, bool $b, mixed $m)
{
    # Reviewed by hand, up to their most arguments.
    \strlen($s);
    \trim($s);
    \explode(',', $s, 2);
    \str_replace('a', 'b', $s);
    \max($i, 1, 2, 3);
    \round($f, 2);
    # Known from the metadata, required parameters only.
    \htmlspecialchars($s);
    \str_word_count($s);
    \wordwrap($s);
    \strcoll($s, 'x');
    \strncmp($s, 'x', $i);
    \version_compare($s, '8.1.0');
    \hash('sha256', $s);
    \hash_equals($s, 'x');
    \preg_quote($s);
    \checkdate($i, $i, $i);
    \sqrt($f);
    \atan2($f, $f);
    \gettype($b);
    \get_debug_type($i);
    \is_scalar($s);
    \urlencode($s);
    \base64_encode($s);
    \sprintf('%%');
    \array_fill($i, 3, $s);
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\Pure;

function pure1(string $s, int $i, float $f, bool $b, mixed $m)
{
    # Reviewed by hand, up to their most arguments.
    $pure = \strlen($s);
    $pure = \trim($s);
    $pure = \explode(',', $s, 2);
    $pure = \str_replace('a', 'b', $s);
    $pure = \max($i, 1, 2, 3);
    $pure = \round($f, 2);
    # Known from the metadata, required parameters only.
    $pure = \htmlspecialchars($s);
    $pure = \str_word_count($s);
    $pure = \wordwrap($s);
    $pure = \strcoll($s, 'x');
    $pure = \strncmp($s, 'x', $i);
    $pure = \version_compare($s, '8.1.0');
    $pure = \hash('sha256', $s);
    $pure = \hash_equals($s, 'x');
    $pure = \preg_quote($s);
    $pure = \checkdate($i, $i, $i);
    $pure = \sqrt($f);
    $pure = \atan2($f, $f);
    $pure = \gettype($b);
    $pure = \get_debug_type($i);
    $pure = \is_scalar($s);
    $pure = \urlencode($s);
    $pure = \base64_encode($s);
    $pure = \sprintf('%%');
    $pure = \array_fill($i, 3, $s);
}

?>
