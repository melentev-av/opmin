<?php

declare(strict_types=1);

namespace Opmin\Module\Harness;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Common\FileSystem\FS;

/**
 * Where `harness/worker.php` is on disk for `php.binary`.
 *
 * From sources it is the repository's `harness/`. Inside the PHAR (or the static binary) the files are
 * extracted to a directory named by the opmin version and the content hash: `php.binary` cannot read
 * files from inside the PHAR. `OPMIN_HARNESS_DIR` sets the extraction root (it must be visible to
 * `php.binary`, e.g. inside the project for a Docker wrapper).
 *
 * @internal
 */
final class HarnessFiles
{
    public const SOURCE = Info::ROOT_DIR . '/harness';

    /**
     * Absolute path of `worker.php`.
     */
    public static function worker(): Path
    {
        /** @var Path|null $worker */
        static $worker = null;

        return $worker ??= self::locate();
    }

    private static function locate(): Path
    {
        $source = Path::create(self::SOURCE);
        if (\Phar::running(false) === '') {
            return $source->join('worker.php');
        }

        $files = self::files($source);
        $hash = \hash('sha256', \implode("\0", \array_map(
            static fn(string $relative): string => $relative . "\0" . (string) \hash_file('sha256', (string) $source->join($relative)),
            $files,
        )));
        $env = \getenv('OPMIN_HARNESS_DIR');
        $root = \is_string($env) && $env !== '' ? Path::create($env) : Path::create(\sys_get_temp_dir());
        $target = $root->join(\sprintf('opmin-harness-%s-%s', Info::version(), \substr($hash, 0, 12)));
        if (!$target->join('worker.php')->isFile()) {
            # Extract into a temporary directory and rename: parallel runs never see half a harness.
            $tmp = Path::create((string) $target . '.' . \bin2hex(\random_bytes(4)));
            foreach ($files as $relative) {
                FS::mkdir((string) $tmp->join($relative)->parent());
                \copy((string) $source->join($relative), (string) $tmp->join($relative));
            }

            @\rename((string) $tmp, (string) $target) or FS::remove($tmp);
        }

        return $target->join('worker.php');
    }

    /**
     * @return list<non-empty-string> Paths relative to the harness directory, sorted.
     */
    private static function files(Path $source): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $source, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = \substr($file->getPathname(), \strlen((string) $source) + 1);
                $relative = \str_replace('\\', '/', $relative);
                $relative === '' or $files[] = $relative;
            }
        }

        \sort($files, \SORT_STRING);

        return $files;
    }
}
