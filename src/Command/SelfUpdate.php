<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Info;
use Opmin\Module\Release\GitHubReleases;
use Opmin\Module\Release\Installation;
use Opmin\Module\Release\Platform;
use Opmin\Module\Release\ReleaseException;
use Opmin\Module\Release\Signature;
use Opmin\Module\Release\Updater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Replaces the running static binary (or PHAR) with a release from GitHub: the signature of `sha256sum.txt`
 * and the checksum of the download are checked before anything is replaced.
 *
 * ```bash
 * opmin self-update            # the latest release
 * opmin self-update --check    # only tell whether a newer release exists
 * opmin self-update --to=1.2.3 # a given version, also older (what .opmin-version of a project asks for)
 * ```
 *
 * Exit codes: 0 — updated, up to date, or `--check` done; 1 — the release cannot be found, downloaded,
 * verified or written, or opmin runs from sources.
 *
 * @internal
 */
#[AsCommand(
    name: 'self-update',
    description: 'Update the opmin binary to the latest release',
)]
final class SelfUpdate extends Base
{
    protected const bool CHECK_VERSION = false;

    public function configure(): void
    {
        parent::configure();
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Only tell whether a newer release exists');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Install this version instead of the latest one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        /** @var StyleInterface $style */
        $style = $this->container->get(StyleInterface::class);
        /** @var string|null $to */
        $to = $input->getOption('to');
        $current = Info::version();

        try {
            $installation = Installation::current();
            # Sources cannot be replaced: say so before going to the network.
            $input->getOption('check') or $installation->asset('', '');
            $source = GitHubReleases::fromEnvironment();
            $to = $to === null ? '' : \ltrim(\trim($to), 'v');
            $release = $to === '' ? $source->latest() : $source->get($to);

            if ($input->getOption('check')) {
                $style->text(\sprintf('Installed: %s, latest release: %s.', $current, $release->version));
                self::isNewer($release->version, $current)
                    ? $style->note("A newer opmin is available: opmin self-update (or --to={$release->version}).")
                    : $style->success('opmin is up to date.');
                return Command::SUCCESS;
            }

            if ($release->version === $current) {
                $style->success("opmin {$current} is already installed.");
                return Command::SUCCESS;
            }

            $target = $installation->kind === Installation::BINARY ? Platform::current() : 'phar';
            (new Updater($source, Signature::bundled(), $target))->update($installation, $release);
        } catch (ReleaseException $e) {
            $style->error($e->getMessage());
            return Command::FAILURE;
        }

        $style->success(\sprintf('opmin is updated: %s → %s (%s).', $current, $release->version, (string) $installation->file));
        $style->text('Update the Claude Code skill as well when you use it: opmin skill:update [--global]');

        return Command::SUCCESS;
    }

    protected function getConfigFile(InputInterface $input): ?string
    {
        return null;
    }

    /**
     * A build from sources (`experimental`) is older than any release.
     */
    private static function isNewer(string $release, string $current): bool
    {
        return \preg_match('/^\d+\.\d+/', $current) !== 1 || \version_compare($release, $current, '>');
    }
}
