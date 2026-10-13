<?php

namespace Opmin\Tests\Fixture\Pure;

final class Text4 implements \Stringable
{
    public function __toString(): string
    {
        return 'text';
    }
}

/**
 * @param string $doc
 */
function trap4(Text4 $o, int $i, float $f, ?string $n, mixed $m, array $a, string $s, $doc)
{
    # __toString(), a coercion (deprecation or TypeError), types only from PHPDoc.
    \strlen($o);
    \htmlspecialchars($o);
    \strlen($i);
    \dechex($f);
    \str_word_count($n);
    \urlencode($m);
    \strlen($doc);
    # A callable runs user code; an array may hold objects.
    \array_map('strtolower', $a);
    \call_user_func('strtolower', $s);
    \is_callable($s);
    \implode(',', $a);
    # Named, unpacked, first-class callable, by reference.
    \strlen(string: $s);
    \strlen(...[$s]);
    \strlen(...);
    \preg_match('/a/', $s, $matches);
}

?>
