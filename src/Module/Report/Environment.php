<?php

declare(strict_types=1);

namespace Opmin\Module\Report;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Opcode\OptimizerSettings;
use Opmin\Module\Php\PhpBinary;
use Opmin\Module\Project\Project;
use Rector\Application\VersionResolver;

/**
 * What a run depends on (brief, «Версии PHP и инструментов»): opcode counts on the PHP runtime and
 * the optimizer settings, the rules on Rector and `php.target`, the static check on the project's
 * PHPStan. A run with another of them is not comparable with an earlier one.
 *
 * @internal
 */
final readonly class Environment
{
    /** Fields compared with the previous run and why their change matters. */
    private const COMPARED = [
        'php' => 'opcode counts are not comparable',
        'optimizer_hash' => 'opcode counts are not comparable',
        'php_target' => 'the rules and PHPStan see another PHP version',
        'opmin' => 'the rules and the checks may differ',
        'rector' => 'the same rule may rewrite code differently',
        'phpstan' => 'the static check may see other errors',
    ];

    public function __construct(
        public string $opmin,
        public string $php,
        public ?string $phpTarget,
        public string $optimizerHash,
        public string $rector,
        public ?string $phpstan,
        public ?string $testRunner = null,
        public ?string $formatter = null,
    ) {}

    public static function detect(Project $project, PhpBinary $php, ?string $phpTarget, ?string $testRunner = null, ?string $formatter = null): self
    {
        return new self(
            Info::version(),
            $php->version,
            $phpTarget,
            OptimizerSettings::hash($php),
            VersionResolver::PACKAGE_VERSION,
            self::installedVersion($project->root, 'phpstan/phpstan'),
            $testRunner,
            $formatter,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn(string $key): ?string => isset($data[$key]) && \is_scalar($data[$key]) ? (string) $data[$key] : null;

        return new self(
            $string('opmin') ?? '',
            $string('php') ?? '',
            $string('php_target'),
            $string('optimizer_hash') ?? '',
            $string('rector') ?? '',
            $string('phpstan'),
            $string('test_runner'),
            $string('formatter'),
        );
    }

    /**
     * The environment of the latest earlier run under `runs/` with a report; null when there is none.
     *
     * @return array{string, self}|null The run directory (relative to `$runsDir`) and its environment.
     */
    public static function previous(Path $runsDir, ?Path $except = null): ?array
    {
        $reports = \glob((string) $runsDir->join('*', 'report.json'));
        $reports === false and $reports = [];
        \rsort($reports, \SORT_STRING);
        foreach ($reports as $report) {
            $dir = \dirname($report);
            if ($except !== null && \realpath($dir) === \realpath((string) $except)) {
                continue;
            }

            /** @var mixed $data */
            $data = \json_decode((string) \file_get_contents($report), true);
            /** @var mixed $environment */
            $environment = \is_array($data) ? ($data['environment'] ?? null) : null;
            if (\is_array($environment)) {
                return [\basename($dir), self::fromArray($environment)];
            }
        }

        return null;
    }

    /**
     * Warnings about what changed since `$previous`.
     *
     * @return list<non-empty-string>
     */
    public function changesSince(self $previous, string $run): array
    {
        $now = $this->toArray();
        $was = $previous->toArray();
        $warnings = [];
        foreach (self::COMPARED as $field => $why) {
            if (($now[$field] ?? null) !== ($was[$field] ?? null)) {
                $warnings[] = \sprintf(
                    '%s changed since the run %s: %s → %s (%s).',
                    $field,
                    $run,
                    $was[$field] ?? 'none',
                    $now[$field] ?? 'none',
                    $why,
                );
            }
        }

        return $warnings;
    }

    /**
     * @return array{opmin: string, php: string, php_target: ?string, optimizer_hash: string, rector: string, phpstan: ?string, test_runner: ?string, formatter: ?string}
     */
    public function toArray(): array
    {
        return [
            'opmin' => $this->opmin,
            'php' => $this->php,
            'php_target' => $this->phpTarget,
            'optimizer_hash' => $this->optimizerHash,
            'rector' => $this->rector,
            'phpstan' => $this->phpstan,
            'test_runner' => $this->testRunner,
            'formatter' => $this->formatter,
        ];
    }

    /**
     * Version of a package installed in the project, from Composer's `installed.json`.
     */
    private static function installedVersion(Path $root, string $package): ?string
    {
        $file = $root->join('vendor', 'composer', 'installed.json');
        if (!$file->exists()) {
            return null;
        }

        /** @var array{packages?: list<array{name?: mixed, version?: mixed}>}|list<array{name?: mixed, version?: mixed}>|null $data */
        $data = \json_decode((string) \file_get_contents((string) $file), true);
        # Composer 2: {"packages": [...]}, Composer 1: [...].
        $packages = \is_array($data) ? ($data['packages'] ?? $data) : [];
        foreach ($packages as $entry) {
            /** @var mixed $version */
            $version = $entry['version'] ?? null;
            if (($entry['name'] ?? null) === $package && \is_string($version)) {
                return \ltrim($version, 'v');
            }
        }

        return null;
    }
}
