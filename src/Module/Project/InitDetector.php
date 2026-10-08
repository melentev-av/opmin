<?php

declare(strict_types=1);

namespace Opmin\Module\Project;

use Internal\Path;
use Opmin\Module\Config\Schema\TestRunner;
use Opmin\Module\Optimize\Formatter;
use Opmin\Module\Tests\PestAdapter;
use Opmin\Module\Tests\PhpUnitAdapter;
use Opmin\Module\Tests\TestoAdapter;

/**
 * What `opmin init` writes instead of the defaults: the project's PHP target, code directories, test
 * runner, formatter and PHPStan, read from `composer.json` and the config files in the project root.
 * A key that cannot be detected keeps its default.
 *
 * @internal
 */
final class InitDetector
{
    /** Directories that usually hold the code, in order of preference. */
    private const CODE_DIRS = ['src', 'app', 'lib'];

    /**
     * @return array<non-empty-string, array{mixed, non-empty-string}> Value and where it comes from, by key path.
     *         An enum is its backed value, as in YAML.
     */
    public static function detect(Path $root): array
    {
        # The config's own directory is the project: a composer.json further up belongs to another one.
        $hasComposer = $root->join('composer.json')->isFile();
        $project = $hasComposer ? Project::detect($root, $root) : new Project($root, false, null);
        $found = [];

        $project->phpTarget === null or $found['php.target'] = [$project->phpTarget, 'composer.json'];

        $dirs = \array_values(\array_filter(self::CODE_DIRS, static fn(string $dir): bool => $root->join($dir)->isDir()));
        $dirs === [] or $found['paths'] = [$dirs, 'directories ' . \implode(', ', \array_map(static fn(string $d): string => "{$d}/", $dirs))];

        $runner = match (true) {
            PestAdapter::detect($project) => TestRunner::Pest,
            PhpUnitAdapter::detect($project) => TestRunner::PhpUnit,
            TestoAdapter::detect($project) => TestRunner::Testo,
            default => null,
        };
        $runner === null or $found['tests.runner'] = [$runner->value, 'composer.json and the runner config'];

        $format = Formatter::detect($root);
        $format === null or $found['commands.format'] = [$format, 'the formatter config'];

        $composer = $hasComposer ? (string) \file_get_contents((string) $root->join('composer.json')) : '';
        $phpstan = $root->join('vendor/bin/phpstan')->isFile() || \str_contains($composer, '"phpstan/phpstan"')
            || \str_contains($composer, '"larastan/larastan"') || \str_contains($composer, '"nunomaduro/larastan"');
        $phpstan or $found['commands.phpstan'] = [null, 'no PHPStan in the project'];

        return $found;
    }
}
