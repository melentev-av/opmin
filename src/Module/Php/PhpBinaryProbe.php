<?php

declare(strict_types=1);

namespace Opmin\Module\Php;

use Symfony\Component\Process\Process;

/**
 * Finds out what `php.binary` is: version, Zend extensions, how to load OPcache.
 *
 * ```php
 * $php = (new PhpBinaryProbe())->probe('php8.1');
 * ```
 *
 * @internal
 */
final class PhpBinaryProbe
{
    /** Oldest PHP of the analyzed projects (and of the harness). */
    public const MIN_VERSION_ID = 80100;

    private const CODE = <<<'PHP'
        echo json_encode([
            'version' => PHP_VERSION,
            'id' => PHP_VERSION_ID,
            'opcache' => function_exists('opcache_compile_file'),
            'zend' => get_loaded_extensions(true),
        ]);
        PHP;

    /** @var array<string, PhpBinary> */
    private array $cache = [];

    /**
     * @param non-empty-string $binary
     * @throws PhpBinaryException
     */
    public function probe(string $binary): PhpBinary
    {
        return $this->cache[$binary] ??= $this->doProbe($binary);
    }

    /**
     * @param non-empty-string $binary
     */
    private function doProbe(string $binary): PhpBinary
    {
        $info = $this->run($binary, []);
        $loadArgs = [];
        if (!$info['opcache'] && $info['id'] >= self::MIN_VERSION_ID) {
            # OPcache is often built but not enabled for CLI (Docker images, distro packages).
            $loadArgs = ['-d', 'zend_extension=opcache'];
            $info = $this->run($binary, $loadArgs);
        }

        $info['id'] >= self::MIN_VERSION_ID or throw new PhpBinaryException(\sprintf(
            'php.binary `%s` is PHP %s; opmin needs PHP 8.1 or newer to count opcodes. '
            . 'Set php.binary to the PHP your production runs.',
            $binary,
            $info['version'],
        ));
        $info['opcache'] or throw new PhpBinaryException(\sprintf(
            'php.binary `%s` (PHP %s) has no OPcache: install the opcache extension or point '
            . 'php.binary at a PHP build that has it (OPcache compiles the code for counting).',
            $binary,
            $info['version'],
        ));

        $zend = $info['zend'];
        \sort($zend);

        return new PhpBinary($binary, $info['version'], $info['id'], $loadArgs, $zend);
    }

    /**
     * @param non-empty-string $binary
     * @param list<non-empty-string> $loadArgs
     * @return array{version: non-empty-string, id: int<0, max>, opcache: bool, zend: list<non-empty-string>}
     */
    private function run(string $binary, array $loadArgs): array
    {
        $process = new Process([$binary, ...$loadArgs, '-d', 'xdebug.mode=off', '-d', 'display_errors=stderr', '-r', self::CODE]);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new PhpBinaryException(\sprintf('php.binary `%s` cannot be started: %s', $binary, $e->getMessage()), previous: $e);
        }

        /** @var mixed $info */
        $info = \json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || !\is_array($info)) {
            throw new PhpBinaryException(\sprintf(
                'php.binary `%s` is not a working PHP CLI (exit code %s): %s',
                $binary,
                (string) $process->getExitCode(),
                \trim($process->getErrorOutput() . $process->getOutput()) ?: 'no output',
            ));
        }

        /** @var array{version: non-empty-string, id: int<0, max>, opcache: bool, zend: list<non-empty-string>} $info */
        return $info;
    }
}
