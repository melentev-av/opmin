<?php

/**
 * The opmin harness: runs under the project's PHP (`php.binary`, 8.1+), no dependencies, no
 * autoloader of its own. Protocol: docs/harness-protocol.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Value.php';
require_once __DIR__ . '/src/Probe.php';
require_once __DIR__ . '/src/Coverage.php';
require_once __DIR__ . '/src/FileOverride.php';
require_once __DIR__ . '/src/Fakes.php';
require_once __DIR__ . '/src/Journal.php';
require_once __DIR__ . '/src/Describer.php';
require_once __DIR__ . '/src/Mocks.php';
require_once __DIR__ . '/src/Builder.php';
require_once __DIR__ . '/src/Reflector.php';
require_once __DIR__ . '/src/Isolation.php';
require_once __DIR__ . '/src/ErrorCollector.php';
require_once __DIR__ . '/src/Invoker.php';
require_once __DIR__ . '/src/Loader.php';
require_once __DIR__ . '/src/Calls.php';
require_once __DIR__ . '/src/Worker.php';
