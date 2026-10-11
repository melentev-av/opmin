<?php

declare(strict_types=1);

namespace Opmin\Module\Check\Ci;

use Internal\Path;
use Opmin\Bootstrap;
use Opmin\Info;
use Opmin\Module\Config\Exception\ConfigException;
use Opmin\Module\Config\Schema;
use Opmin\Module\Php\PhpBinaryException;
use Opmin\Module\Php\PhpBinaryProbe;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * `opmin init --ci=github|gitlab`: writes the job of the CI guard next to the config and tells what else it
 * needs — the baseline, taken with the same image and committed.
 *
 * The PHP of the image is the minor of `php.binary`, not `php.target`: the target may be the lowest
 * `require.php` of composer.json, while the baseline is compared by the PHP minor it was taken with.
 *
 * Exit codes: 0 — written; 2 — an unknown CI system, the file exists (never overwritten), `php.binary`
 * does not start, or this opmin is not a release (it has no image).
 *
 * @internal
 */
final readonly class CiSetup
{
    public function __construct(
        private StyleInterface $style,
    ) {}

    /**
     * @param Path $configPath `opmin.yaml` of the project: the job is written next to it, `php.binary` read from it.
     * @throws ConfigException An invalid config (exit code 2 through `Base::run()`).
     */
    public function run(string $ci, Path $configPath, InputInterface $input): int
    {
        $system = CiSystem::tryFrom(\strtolower($ci));
        if ($system === null) {
            $this->style->error(\sprintf('--ci takes %s, not `%s`.', CiSystem::names(), $ci));
            return Command::INVALID;
        }

        $version = Info::version();
        if (\preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $this->style->error(
                "This opmin ({$version}) is not a release, and the CI guard runs the image of a release: "
                . 'install a release of opmin and run `opmin init --ci=' . $system->value . '` with it.',
            );
            return Command::INVALID;
        }

        $root = $configPath->parent();
        $file = $root->join($system->file());
        if ($file->exists()) {
            $this->style->error("{$file} already exists: opmin does not overwrite it. Remove it to write a new one, or edit it by hand.");
            return Command::INVALID;
        }

        $binary = $this->phpBinary($configPath, $input);
        try {
            $php = (new PhpBinaryProbe())->probe($binary);
        } catch (PhpBinaryException $e) {
            $this->style->error(
                $e->getMessage() . "\n`init --ci` takes the PHP minor of the image from php.binary: point it at the PHP "
                . 'of production in opmin.yaml or with --set=php.binary=<path>.',
            );
            return Command::INVALID;
        }

        /** @var non-empty-string $minor */
        $minor = \intdiv($php->versionId, 10000) . '.' . (\intdiv($php->versionId, 100) % 100);

        \is_dir((string) $file->parent()) or \mkdir((string) $file->parent(), 0777, true);
        \file_put_contents((string) $file, CiWorkflow::render($system, $minor, $version));

        $this->style->success("CI guard written: {$file}");
        $this->style->text([
            "PHP {$minor}: the minor of php.binary `{$binary}` (PHP {$php->version}).",
            'The check must run with the PHP minor of production: if production runs another one, set',
            'php.binary to it, remove the file and run `opmin init --ci=' . $system->value . '` again.',
        ]);
        $system === CiSystem::Gitlab and $this->style->text([
            '',
            'Include it from .gitlab-ci.yml (opmin does not edit that file):',
            '',
            ...\array_map(static fn(string $line): string => '  ' . $line, \explode("\n", CiWorkflow::gitlabInclude())),
        ]);
        $this->style->text([
            '',
            'The check needs a baseline taken with the same image. Create it in the project root and commit',
            'opmin.baseline.json' . ($configPath->exists() ? '' : ' and opmin.yaml (run `opmin init` first)') . ':',
            '',
            '  ' . CiWorkflow::baselineCommand($minor, $version),
        ]);

        return Command::SUCCESS;
    }

    /**
     * `php.binary` as a command reading the project's config would get it: `init` itself does not read the
     * config (it must work when the config is missing or broken), so it is read here.
     *
     * @return non-empty-string
     */
    private function phpBinary(Path $configPath, InputInterface $input): string
    {
        /** @var list<string> $overrides */
        $overrides = $input->getOption('set');
        /** @var array<string, mixed> $options */
        $options = $input->getOptions();
        /** @var array<string, mixed> $arguments */
        $arguments = $input->getArguments();
        $container = Bootstrap::init()->withConfig(
            file: $configPath->isFile() ? (string) $configPath : null,
            inputOptions: $options,
            inputArguments: $arguments,
            environment: \getenv(),
            overrides: $overrides,
        )->finish();

        /** @var Schema\Php $php */
        $php = $container->get(Schema\Php::class);

        return $php->binary;
    }
}
