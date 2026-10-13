<?php

namespace App\ClosureUse99;

$s = 'a';
$i = 1;

return static function () use ($s, $i) {
    return $i;
};

?>
