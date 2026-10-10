<?php

declare(strict_types=1);

namespace Opmin\Module\Package;

use Internal\Path;
use Opmin\Info;
use Opmin\Module\Common\FileSystem\FS;
use Opmin\Module\Config\Schema;
use Opmin\Module\Project\InitDetector;
use Opmin\Module\Release\Installation;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Process\Process;

/**
 * `opmin optimize <git-url> --ref=<tag>` (brief, «Безопасность режима git-пакета»): composer install and
 * the tests of a package are foreign code, so they run isolated.
 *
 * 1. The package is cloned on the host into a temporary workspace (branch `opmin/<timestamp>`).
 * 2. `require.php` and `ext-*` of the package are checked against the PHP that will run it.
 * 3. `composer install --no-scripts --no-plugins` (`--allow-scripts` runs them).
 * 4. opmin optimizes the clone — in Docker by default: no network, a read-only root file system except the
 *    workspace and /tmp, CPU and memory limits; without Docker only after a confirmation (`--yes`).
 * 5. `runs/` with the report and the patch are copied to the current directory; the workspace is removed.
 *
 * @internal
 */
final readonly class PackageRun
{
    /**
     * @param non-empty-string $url
     * @param list<string> $innerOptions Options of the inner `opmin optimize`.
     * @param list<string> $paths Paths inside the package; none — its opmin.yaml, or the detected code directories.
     * @param bool $docker Run in Docker (it is available and `--no-docker` is not given).
     */
    public function __construct(
        private string $url,
        private ?string $ref,
        private array $innerOptions,
        private array $paths,
        private bool $docker,
        private bool $allowScripts,
        private Schema\Package $config,
        private Schema\Php $php,
        private StyleInterface $style,
        private Path $cwd,
    ) {}

    /**
     * Whether a Docker daemon answers.
     */
    public static function dockerAvailable(): bool
    {
        $process = new Process(['docker', 'info', '--format', '{{.ServerVersion}}']);
        $process->setTimeout(30);
        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && \trim($process->getOutput()) !== '';
    }

    /**
     * `docker run` for the workspace: the root file system is read-only, only the workspace and /tmp are
     * writable; the user of the host owns what the container writes; no network unless asked.
     *
     * @param non-empty-string $image
     * @param non-empty-string|null $user `uid:gid`.
     * @param Path|null $composerCache Download cache of composer kept between runs (only downloads: the
     *        anonymous downloads of a fresh cache hit the rate limit of GitHub).
     * @return list<string>
     */
    public static function dockerRun(Path $workspace, string $image, bool $network, Schema\Package $config, ?string $user, ?Path $composerCache = null): array
    {
        $args = [
            'docker', 'run', '--rm', '--init',
            '--read-only', '--tmpfs', '/tmp:rw,exec,size=2g',
            '--memory', $config->memory,
            '--security-opt', 'no-new-privileges',
            '-e', 'HOME=/tmp', '-e', 'COMPOSER_HOME=/tmp/composer', '-e', 'OPMIN_NO_DELEGATE=1',
            '-v', (string) $workspace . ':/workspace',
            '-w', '/workspace/package',
        ];
        $composerCache === null or \array_push($args, '-v', (string) $composerCache . ':/composer-cache', '-e', 'COMPOSER_CACHE_DIR=/composer-cache');
        $network or \array_push($args, '--network', 'none');
        # The Docker VM may have fewer cores than the host: without package.cpus the container gets all of the VM's.
        $config->cpus === null or \array_push($args, '--cpus', (string) $config->cpus);
        $user === null or \array_push($args, '--user', $user);
        $args[] = $image;

        return $args;
    }

    /**
     * The reason of a failed `composer install`: its «Problem» lines (a missing extension, a PHP version)
     * come first and are followed by long hints, so the tail alone would lose the reason.
     */
    public static function composerProblem(string $output): string
    {
        $lines = \array_values(\array_filter(\explode("\n", \trim($output)), static fn(string $l): bool => \trim($l) !== ''));
        $start = null;
        foreach (['/^\s*Problem \d+/', '/^\s*In \S+ line \d+:/'] as $pattern) {
            foreach ($lines as $i => $line) {
                if (\preg_match($pattern, $line) === 1) {
                    $start = $i;
                    break 2;
                }
            }
        }

        $lines = $start === null ? \array_slice($lines, -15) : \array_slice($lines, $start, 15);
        # The synopsis of the command that composer prints after an exception says nothing.
        foreach ($lines as $i => $line) {
            if (\preg_match('/^\s*install \[/', $line) === 1) {
                $lines = \array_slice($lines, 0, $i);
                break;
            }
        }

        return \trim(\implode("\n", $lines));
    }

    /**
     * @param \Closure(): bool $confirm Asks whether to run foreign code without isolation.
     * @return int Exit code of the inner run.
     * @throws PackageException
     */
    public function run(\Closure $confirm): int
    {
        $this->style->text("Cloning {$this->url}" . ($this->ref === null ? '' : " at {$this->ref}") . '...');
        $checkout = Checkout::create($this->url, $this->ref, $this->workspaceRoot());
        try {
            $requirements = Requirements::fromComposerJson($checkout->composerJson());
            $image = $this->docker ? $this->image($requirements) : null;
            if ($image === null) {
                $this->checkLocalPhp($requirements);
                $this->style->warning([
                    'Docker is not used: composer install and the tests of the package run on this machine without isolation.',
                    'They are foreign code. Use Docker (default when it runs) or review the package first.',
                ]);
                $confirm() or throw new PackageException('Stopped: the package was not run. Pass --yes to run foreign code without Docker.');
            }

            $image === null or $this->checkMount($checkout, $image);
            $this->style->text($image === null ? 'composer install (no isolation)...' : "composer install in {$image}...");
            $this->install($checkout, $image);
            $this->style->text($image === null
                ? 'Optimizing the package...'
                : 'Optimizing the package in Docker: no network, read-only except the workspace...');
            $code = $this->optimize($checkout, $image, $this->paths($checkout));
            $this->collect($checkout);

            return $code;
        } finally {
            $checkout->remove();
        }
    }

    /**
     * Regular files and directories only: links of the package are not followed out of the workspace.
     */
    private static function copyTree(string $from, string $to): void
    {
        if (\is_link($from)) {
            return;
        }

        if (\is_file($from)) {
            \copy($from, $to);
            return;
        }

        \is_dir($to) or \mkdir($to, 0777, true);
        $entries = \scandir($from);
        foreach ($entries === false ? [] : $entries as $entry) {
            $entry === '.' || $entry === '..' or self::copyTree("{$from}/{$entry}", "{$to}/{$entry}");
        }
    }

    private static function configured(Checkout $checkout): bool
    {
        return $checkout->dir->join('opmin.yaml')->isFile() || $checkout->dir->join('opmin.yaml.dist')->isFile();
    }

    /**
     * The image for the package: `package.docker_image` with the PHP minor of `php.target`, or the newest
     * minor the package allows.
     *
     * @return non-empty-string
     * @throws PackageException
     */
    private function image(Requirements $requirements): string
    {
        $minors = $requirements->imageMinors();
        $minors === [] and throw new PackageException(\sprintf(
            'The package requires PHP %s; opmin images exist for PHP %s.',
            (string) $requirements->php,
            \implode(', ', \array_reverse(Requirements::IMAGE_MINORS)),
        ));

        $target = $this->php->target;
        if ($target !== null) {
            \in_array($target, $minors, true) or throw new PackageException(\sprintf(
                'php.target is %s, but the package requires PHP %s (images: %s).',
                $target,
                (string) $requirements->php,
                \implode(', ', $minors),
            ));
            $minors = [$target];
        }

        $image = \str_replace(['{version}', '{php}'], [Info::version(), $minors[0]], $this->config->dockerImage);
        $image === '' and throw new PackageException('package.docker_image is empty.');
        $inspect = new Process(['docker', 'image', 'inspect', $image]);
        $inspect->run();
        if (!$inspect->isSuccessful()) {
            $this->style->text("Pulling {$image}...");
            $pull = new Process(['docker', 'pull', '--quiet', $image]);
            $pull->setTimeout(1800);
            $pull->run();
            $pull->isSuccessful() or throw new PackageException(\sprintf(
                "Cannot pull the image %s: %s\nSet package.docker_image to an image with opmin and the PHP of the package, or run with --no-docker.",
                $image,
                \trim($pull->getErrorOutput()),
            ));
        }

        return $image;
    }

    /**
     * Docker Desktop, colima and OrbStack share only some host directories with their VM (home, /tmp): a
     * workspace elsewhere is an empty directory in the container.
     */
    private function workspaceRoot(): Path
    {
        if ($this->config->workspace !== null) {
            $root = Path::create($this->config->workspace);
            $root->isAbsolute() or $root = $this->cwd->join($this->config->workspace);

            return $root;
        }

        $home = \getenv('HOME');

        return $this->docker && \is_string($home) && $home !== ''
            ? Path::create($home)->join('.cache', 'opmin', 'packages')
            : Path::create(\sys_get_temp_dir());
    }

    /**
     * @param non-empty-string $image
     * @throws PackageException
     */
    private function checkMount(Checkout $checkout, string $image): void
    {
        $process = new Process([...$this->docker($checkout, $image, network: false), 'test', '-f', '/workspace/package/composer.json']);
        $process->setTimeout(300);
        $process->run();
        $process->isSuccessful() or throw new PackageException(\sprintf(
            "Docker does not see the workspace %s (%s).\nShare the directory with the Docker VM, or set package.workspace to a shared one (e.g. under your home).",
            (string) $checkout->workspace,
            \trim($process->getErrorOutput()) ?: 'the clone is empty in the container',
        ));
    }

    /**
     * Without Docker the package runs under php.binary: it must be a PHP the package supports.
     *
     * @throws PackageException
     */
    private function checkLocalPhp(Requirements $requirements): void
    {
        $process = new Process([$this->php->binary, '-r', 'echo json_encode([PHP_VERSION, get_loaded_extensions()]);']);
        try {
            $process->run();
        } catch (\Throwable) {
        }

        /** @var mixed $info */
        $info = \json_decode($process->getOutput(), true);
        if (!\is_array($info) || !\is_string($info[0] ?? null) || !\is_array($info[1] ?? null)) {
            throw new PackageException("php.binary `{$this->php->binary}` cannot be started: run opmin doctor.");
        }

        /** @var array{string, list<string>} $info */
        $loaded = $info[1];
        $hint = $requirements->imageMinors() === []
            ? ''
            : "\nUse Docker: the image ghcr.io/" . Info::REPOSITORY . ':' . Info::version() . '-php' . $requirements->imageMinors()[0]
                . ' has a PHP the package supports (run without --no-docker).';
        $requirements->allowsPhp($info[0]) or throw new PackageException(
            "The package requires PHP {$requirements->php}, php.binary is PHP {$info[0]}." . $hint,
        );
        $missing = $requirements->missingExtensions($loaded);
        $missing === [] or throw new PackageException(
            'The package needs PHP extensions php.binary lacks: ' . \implode(', ', $missing) . '.' . $hint,
        );
    }

    /**
     * @throws PackageException
     */
    /**
     * @param non-empty-string|null $image
     */
    private function install(Checkout $checkout, ?string $image): void
    {
        # Packages require Xdebug for the coverage of their tests (league/csv); opmin takes it with pcov.
        $args = ['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--ignore-platform-req=ext-xdebug'];
        $this->allowScripts or \array_push($args, '--no-scripts', '--no-plugins');
        # The network is on here: dependencies are downloaded. It is off for the tests.
        $process = new Process($image === null ? $args : [...$this->docker($checkout, $image, network: true), ...$args], (string) $checkout->dir);
        $process->setTimeout(1800);
        $process->run();
        if ($process->isSuccessful()) {
            return;
        }

        throw new PackageException(\sprintf(
            "composer install of the package failed:\n%s%s",
            self::composerProblem($process->getErrorOutput() . "\n" . $process->getOutput()),
            $this->allowScripts ? '' : "\nIf the package needs its composer scripts or plugins, rerun with --allow-scripts (they are foreign code too).",
        ));
    }

    /**
     * @return list<string>
     */
    private function paths(Checkout $checkout): array
    {
        if ($this->paths !== [] || self::configured($checkout)) {
            return $this->paths;
        }

        /** @var list<string> $detected */
        $detected = InitDetector::detect($checkout->dir)['paths'][0] ?? [];

        return $detected;
    }

    /**
     * @param non-empty-string|null $image
     * @param list<string> $paths
     */
    private function optimize(Checkout $checkout, ?string $image, array $paths): int
    {
        $config = [];
        if (!self::configured($checkout)) {
            # opmin needs a config; the package has none: an empty one next to the clone, so the patch and the
            # work tree of the package stay clean, and the cache lands in the workspace with it.
            \file_put_contents((string) $checkout->workspace->join('opmin.yaml'), "# Written by opmin: the package has no config of its own.\n");
            $config[] = '--config=../opmin.yaml';
        }

        $arguments = [...$this->innerOptions, ...$config, '--', ...$paths];
        $command = $image === null
            ? [...Installation::current()->command(), 'optimize', ...$arguments]
            : [...$this->docker($checkout, $image, network: false), 'opmin', 'optimize', ...$arguments];
        $env = \getenv();
        $env['OPMIN_NO_DELEGATE'] = '1';
        $process = \proc_open($command, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes, (string) $checkout->dir, $env);
        \is_resource($process) or throw new PackageException('Cannot start opmin for the package.');

        return \proc_close($process);
    }

    /**
     * @param non-empty-string $image
     * @return list<string>
     */
    private function docker(Checkout $checkout, string $image, bool $network): array
    {
        $user = \function_exists('posix_getuid') && \function_exists('posix_getgid') ? \posix_getuid() . ':' . \posix_getgid() : null;

        $cache = null;
        if ($network) {
            $cache = $this->workspaceRoot()->join('.composer-cache');
            FS::mkdir((string) $cache);
        }

        return self::dockerRun($checkout->workspace, $image, $network, $this->config, $user, $cache);
    }

    /**
     * Copies `runs/` of the clone into the current directory, and every patch next to it as
     * `opmin-<package>[-<ref>].patch`.
     */
    private function collect(Checkout $checkout): void
    {
        $runs = $checkout->dir->join('runs');
        $entries = \is_dir((string) $runs) ? \scandir((string) $runs) : false;
        foreach ($entries === false ? [] : $entries as $run) {
            if ($run === '.' || $run === '..' || !\is_dir((string) $runs->join($run))) {
                continue;
            }

            $target = $this->cwd->join('runs', $run);
            for ($i = 2; $target->exists(); ++$i) {
                $target = $this->cwd->join('runs', "{$run}-{$i}");
            }

            self::copyTree((string) $runs->join($run), (string) $target);
            $this->style->text('Report: ' . (string) $target->join('report.md'));
            $patch = $target->join('opmin.patch');
            if ($patch->isFile() && \filesize((string) $patch) > 0) {
                $suffix = $checkout->ref === null ? '' : '-' . \trim((string) \preg_replace('/[^A-Za-z0-9._-]+/', '-', $checkout->ref), '-');
                $copy = $this->cwd->join("opmin-{$checkout->name}{$suffix}.patch");
                \copy((string) $patch, (string) $copy);
                $this->style->text("Patch: {$copy} (apply in the package with git apply)");
            }
        }
    }
}
