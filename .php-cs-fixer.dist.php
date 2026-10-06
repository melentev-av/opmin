<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

return \Spiral\CodeStyle\Builder::create()
    ->include(__DIR__ . '/bin')
    ->include(__DIR__ . '/src')
    ->include(__DIR__ . '/harness')
    ->include(__DIR__ . '/tests')
    # Fixtures are compiled by OPcache and compared with captured dumps: formatting changes opcodes and lines.
    ->exclude(__DIR__ . '/tests/Fixtures')
    ->include(__FILE__)
    ->build();
