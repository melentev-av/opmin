<?php

namespace Opmin\Tests\Fixture\Pure;

function trap2(string $s)
{
    # The file system, settings and loaded code change between calls; a class name autoloads.
    \file_exists($s);
    \is_file($s);
    \is_dir($s);
    \filemtime($s);
    \filesize($s);
    \ini_get($s);
    \getcwd();
    \extension_loaded($s);
    \phpversion();
    \function_exists($s);
    \class_exists($s);
    \interface_exists($s);
    \method_exists($s, $s);
    \is_a($s, $s, true);
    \defined($s);
    \constant($s);
    \func_get_args();
    \error_reporting();
}

?>
