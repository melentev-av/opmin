<?php

/**
 * Entry point of a harness worker: `php worker.php <token>`.
 *
 * Reads one JSON request per line from stdin, writes one response per line to stdout, each prefixed
 * with the token: anything else on stdout is output of the analyzed code that escaped capture.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

exit(\Opmin\Harness\Worker::main($argv));
