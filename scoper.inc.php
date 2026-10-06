<?php

declare(strict_types=1);

/**
 * PHP-Scoper config for the PHAR (and the static binary built from it).
 *
 * opmin loads the autoloader of the analyzed project into its own process (Rector, type analysis),
 * so every dependency it bundles (php-parser, Rector, PHPStan, Symfony) is prefixed to never
 * collide with the versions in the project's vendor/.
 *
 * Not prefixed:
 * - `Opmin\*` — the tool's own code; it is also the namespace user code sees (`#[\Opmin\Ignore]`).
 *   The harness (`harness/`, `Opmin\Harness\*`) runs in a separate process under the project's PHP
 *   and has no dependencies, so it is never scoped either.
 */
return [
    'prefix' => 'OpminScoped',
    'exclude-namespaces' => [
        'Opmin',
    ],
    // Classes PHPStan/Rector reference by name from stubs and extension configs.
    'exclude-classes' => [
        'Stringable',
        'UnitEnum',
        'BackedEnum',
    ],
    'expose-global-functions' => false,
    'expose-global-classes' => false,
];
