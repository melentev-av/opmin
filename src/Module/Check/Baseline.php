<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

use Internal\Path;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Opcode\FunctionCount;
use Opmin\Module\Opcode\Report\CountReport;

/**
 * `opmin.baseline.json`: the opcode counts `opmin check` compares with, committed to the repository.
 *
 * Not to be confused with `opmin.baseline.yaml` — the changes declined in `optimize --review`.
 *
 * Deterministic: keys sorted, no lines (they shift with every edit of a file) and no timestamps, so
 * the diff of the baseline in a PR shows only the functions whose counts changed.
 *
 * ```json
 * {
 *     "php": "8.3.12", "opmin": "1.2.0", "optimizer_hash": "a1b2c3d4e5f6",
 *     "functions": {"App\\Price::calculate": {"ops": 42, "file": "src/Price.php"}}
 * }
 * ```
 *
 * @internal
 */
final readonly class Baseline
{
    public const string FILE = 'opmin.baseline.json';

    /**
     * @param array<non-empty-string, array{ops: int<0, max>, file: non-empty-string}> $functions By key, sorted.
     */
    private function __construct(
        public string $php,
        public string $opmin,
        public string $optimizerHash,
        public array $functions,
    ) {}

    /**
     * Functions of the report that opmin optimizes: main code is counted, but it grows with every
     * route or config entry and is never optimized, so it is not guarded either.
     */
    public static function fromReport(CountReport $report): self
    {
        $functions = [];
        foreach ($report->functions as $key => $function) {
            $function->optimizable and $functions[$key] = self::entry($function);
        }

        return self::create($report->php, $report->opmin, $report->optimizerHash, $functions);
    }

    /**
     * @param array<non-empty-string, array{ops: int<0, max>, file: non-empty-string}> $functions
     */
    public static function create(string $php, string $opmin, string $optimizerHash, array $functions): self
    {
        \ksort($functions, \SORT_STRING);

        return new self($php, $opmin, $optimizerHash, $functions);
    }

    /**
     * @return array{ops: int<0, max>, file: non-empty-string}
     */
    public static function entry(FunctionCount $function): array
    {
        return ['ops' => $function->opsOpt, 'file' => $function->file];
    }

    /**
     * @throws BaselineException On a missing or broken file.
     */
    public static function load(Path $file): self
    {
        $file->isFile() or throw new BaselineException(\sprintf(
            'No baseline: %s does not exist. Create it with `opmin baseline` and commit it.',
            self::FILE,
        ));

        return self::fromJson((string) \file_get_contents((string) $file), self::FILE);
    }

    /**
     * @throws BaselineException
     */
    public static function fromJson(string $json, string $source): self
    {
        try {
            /** @var mixed $data */
            $data = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            \is_array($data) or throw new \UnexpectedValueException('not a JSON object');
            $php = $data['php'] ?? null;
            $hash = $data['optimizer_hash'] ?? null;
            $opmin = $data['opmin'] ?? '';
            \is_string($php) && \is_string($hash) && \is_string($opmin) && \is_array($data['functions'] ?? null)
                or throw new \UnexpectedValueException('no `php`, `optimizer_hash` or `functions`');

            $functions = [];
            /** @var mixed $entry */
            foreach ($data['functions'] as $key => $entry) {
                $ops = \is_array($entry) ? $entry['ops'] ?? null : null;
                $file = \is_array($entry) ? $entry['file'] ?? null : null;
                \is_string($key) && $key !== '' && \is_int($ops) && $ops >= 0 && \is_string($file) && $file !== ''
                    or throw new \UnexpectedValueException(\sprintf('function `%s` needs `ops` and `file`', (string) $key));
                $functions[$key] = ['ops' => $ops, 'file' => $file];
            }

            return self::create($php, $opmin, $hash, $functions);
        } catch (\JsonException|\UnexpectedValueException $e) {
            throw new BaselineException("{$source} is not an opmin baseline: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Why the counts of this baseline cannot be compared with counts taken now; null — they can.
     *
     * The patch version does not change the optimizer (the captured dumps of the tests are per
     * minor version), and requiring it would break CI with every patch release of PHP.
     */
    public function incompatibility(string $php, string $optimizerHash): ?string
    {
        if (self::minor($this->php) !== self::minor($php)) {
            return \sprintf(
                'The baseline was taken with PHP %s, php.binary is PHP %s: opcode counts are not comparable. '
                . 'Rebuild it with `opmin baseline` on PHP %s, or run the check on PHP %s.',
                $this->php,
                $php,
                self::minor($php),
                self::minor($this->php),
            );
        }

        if ($this->optimizerHash !== $optimizerHash) {
            return \sprintf(
                'The baseline was taken with other optimizer settings (optimizer_hash %s, now %s: Zend extensions '
                . 'or opmin version differ): opcode counts are not comparable. Rebuild it with `opmin baseline`.',
                $this->optimizerHash,
                $optimizerHash,
            );
        }

        return null;
    }

    /**
     * This baseline with the entries of the given functions replaced (null — removed).
     *
     * @param array<non-empty-string, array{ops: int<0, max>, file: non-empty-string}|null> $changes
     */
    public function with(array $changes, string $opmin): self
    {
        $functions = $this->functions;
        foreach ($changes as $key => $entry) {
            if ($entry === null) {
                unset($functions[$key]);
            } else {
                $functions[$key] = $entry;
            }
        }

        return self::create($this->php, $opmin, $this->optimizerHash, $functions);
    }

    public function toJson(): string
    {
        return \json_encode([
            'php' => $this->php,
            'opmin' => $this->opmin,
            'optimizer_hash' => $this->optimizerHash,
            'functions' => (object) $this->functions,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }

    public function write(Path $file): void
    {
        FS::replace((string) $file, $this->toJson());
    }

    private static function minor(string $version): string
    {
        return \implode('.', \array_slice(\explode('.', $version), 0, 2));
    }
}
