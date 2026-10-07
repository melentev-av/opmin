<?php

declare(strict_types=1);

/**
 * PHP-Scoper config for the PHAR (and the static binary built from it).
 *
 * Rector runs in a subprocess that loads the analyzed project's `vendor/autoload.php` next to opmin's, so
 * every dependency opmin bundles (Symfony, internal/*, the property-testing core) is prefixed to never
 * collide with the versions in the project's vendor/.
 *
 * Not prefixed:
 * - `Opmin\*` — the tool's own code; it is also the namespace user code sees (`#[\Opmin\Ignore]`).
 *   The harness (`harness/`, `Opmin\Harness\*`) runs in a separate process under the project's PHP
 *   and has no dependencies, so it is never scoped either.
 * - Rector and PHPStan with what they bundle: they are isolated distributions already (Rector's own
 *   dependencies live under `RectorPrefix*`, PHPStan's inside its PHAR), and their class maps name the
 *   classes unprefixed — a prefixed copy finds nothing. `PhpParser\` and `PHPStan\` stay as they are because
 *   opmin's rules receive Rector's AST nodes and PHPStan's types.
 */
$isolated = static function (string $dir): array {
    $files = [];
    $root = __DIR__ . '/vendor/' . $dir;
    if (!\is_dir($root)) {
        return $files;
    }

    /** @var \SplFileInfo $file */
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
        $file->isFile() and $files[] = (string) $file->getRealPath();
    }

    return $files;
};

return [
    'prefix' => 'OpminScoped',
    'exclude-namespaces' => [
        'Opmin',
        'Rector',
        '/^RectorPrefix\d+/',
        'PhpParser',
        'PHPStan',
    ],
    'exclude-files' => [
        ...$isolated('rector/rector'),
        ...$isolated('phpstan/phpstan'),
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
