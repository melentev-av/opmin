<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * `file://` stream wrapper that serves another file's content for chosen paths while the code is
 * loaded: a version of a file is included under its real path, so `__FILE__`, `__DIR__`,
 * `include_once` and Composer's `files` autoload see the original location.
 *
 * Every other operation goes to the native wrapper. Registered only during `load`.
 *
 * @internal
 */
final class FileOverride
{
    /** @var resource|null */
    public $context;

    /** @var array<string, string> Real path => file whose content is served. */
    private static array $map = [];

    /** @var resource|null */
    private $handle;

    /** @var resource|null */
    private $dir;

    /**
     * @param array<string, string> $map Path => source path.
     */
    public static function register(array $map): void
    {
        self::$map = [];
        foreach ($map as $path => $source) {
            self::$map[self::key((string) $path)] = (string) $source;
        }

        if (self::$map !== []) {
            \stream_wrapper_unregister('file');
            \stream_wrapper_register('file', self::class);
        }
    }

    public static function unregister(): void
    {
        self::$map === [] or \stream_wrapper_restore('file');
        self::$map = [];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $source = \strpbrk($mode, 'waxc+') === false ? self::source($path) : $path;
        $usePath = (bool) ($options & \STREAM_USE_PATH);
        $context = $this->context;
        $handle = self::native(static fn() => $context === null
            ? @\fopen($source, $mode, $usePath)
            : @\fopen($source, $mode, $usePath, $context));
        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;
        $openedPath = $path;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return $this->handle === null ? false : \fread($this->handle, \max(1, $count));
    }

    public function stream_write(string $data): int|false
    {
        return $this->handle === null ? false : \fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || \feof($this->handle);
    }

    public function stream_close(): void
    {
        $handle = $this->handle;
        $this->handle = null;
        $handle === null or \fclose($handle);
    }

    public function stream_flush(): bool
    {
        return $this->handle !== null && \fflush($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $this->handle !== null && \fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int|false
    {
        return $this->handle === null ? false : \ftell($this->handle);
    }

    /**
     * @return array<array-key, int>|false
     */
    public function stream_stat(): array|false
    {
        return $this->handle === null ? false : \fstat($this->handle);
    }

    public function stream_lock(int $operation): bool
    {
        return $this->handle !== null && \flock($this->handle, $operation);
    }

    public function stream_truncate(int $size): bool
    {
        return $this->handle !== null && \ftruncate($this->handle, $size);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return match ($option) {
            \STREAM_OPTION_BLOCKING => \stream_set_blocking($this->handle, (bool) $arg1),
            \STREAM_OPTION_READ_TIMEOUT => \stream_set_timeout($this->handle, $arg1, (int) $arg2),
            \STREAM_OPTION_WRITE_BUFFER => \stream_set_write_buffer($this->handle, $arg2 ?? 0) === 0,
            default => false,
        };
    }

    /**
     * @return resource|false
     */
    public function stream_cast(int $castAs)
    {
        return $this->handle ?? false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return self::native(static function () use ($path, $option, $value): bool {
            return match ($option) {
                \STREAM_META_TOUCH => \is_array($value) && isset($value[0]) ? \touch($path, (int) $value[0], (int) ($value[1] ?? $value[0])) : \touch($path),
                \STREAM_META_OWNER_NAME, \STREAM_META_OWNER => \chown($path, \is_int($value) ? $value : (string) $value),
                \STREAM_META_GROUP_NAME, \STREAM_META_GROUP => \chgrp($path, \is_int($value) ? $value : (string) $value),
                \STREAM_META_ACCESS => \chmod($path, (int) $value),
                default => false,
            };
        });
    }

    /**
     * @return array<array-key, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $source = self::source($path);

        return self::native(static function () use ($source, $flags): array|false {
            $quiet = (bool) ($flags & \STREAM_URL_STAT_QUIET);
            if ($quiet && !\file_exists($source)) {
                return false;
            }

            return $flags & \STREAM_URL_STAT_LINK ? @\lstat($source) : @\stat($source);
        });
    }

    public function unlink(string $path): bool
    {
        return self::native(static fn() => \unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return self::native(static fn() => \rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::native(static fn() => \mkdir($path, $mode, (bool) ($options & \STREAM_MKDIR_RECURSIVE)));
    }

    public function rmdir(string $path, int $options): bool
    {
        return self::native(static fn() => \rmdir($path));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $dir = self::native(static fn() => @\opendir($path));
        if ($dir === false) {
            return false;
        }

        $this->dir = $dir;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return $this->dir === null ? false : \readdir($this->dir);
    }

    public function dir_rewinddir(): bool
    {
        $this->dir === null or \rewinddir($this->dir);

        return $this->dir !== null;
    }

    public function dir_closedir(): bool
    {
        $dir = $this->dir;
        $this->dir = null;
        $dir === null or \closedir($dir);

        return true;
    }

    private static function key(string $path): string
    {
        $path = \str_starts_with($path, 'file://') ? \substr($path, 7) : $path;
        $real = self::native(static fn() => \realpath($path));

        return \is_string($real) ? $real : $path;
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private static function native(\Closure $operation): mixed
    {
        $wrapped = \in_array('file', \stream_get_wrappers(), true) && self::$map !== [];
        $wrapped and \stream_wrapper_restore('file');
        try {
            return $operation();
        } finally {
            if ($wrapped) {
                \stream_wrapper_unregister('file');
                \stream_wrapper_register('file', self::class);
            }
        }
    }

    private static function source(string $path): string
    {
        return self::$map[self::key($path)] ?? $path;
    }
}
