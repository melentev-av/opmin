<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Check\Ci;

use Opmin\Module\Check\Ci\CiSystem;
use Opmin\Module\Check\Ci\CiWorkflow;
use Symfony\Component\Yaml\Yaml;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The job of the CI guard written by `opmin init --ci=`: valid YAML, the pinned image, the baseline command.
 */
#[Test]
#[Covers(CiWorkflow::class)]
#[Covers(CiSystem::class)]
final class CiWorkflowTest
{
    public function githubRunsTheActionAtTheReleaseWithThePhpMinor(): void
    {
        $yaml = CiWorkflow::render(CiSystem::Github, '8.3', '0.4.0');

        /** @var array{on: string, permissions: array<string, string>, jobs: array{opmin: array{runs-on: string, steps: list<array<string, mixed>>}}} $workflow */
        $workflow = Yaml::parse($yaml);
        Assert::same($workflow['on'], 'pull_request');
        Assert::same($workflow['permissions'], ['contents' => 'read']);
        Assert::same($workflow['jobs']['opmin']['runs-on'], 'ubuntu-latest');
        Assert::same($workflow['jobs']['opmin']['steps'], [
            ['uses' => 'actions/checkout@v5', 'with' => ['fetch-depth' => 0]],
            ['uses' => 'melentev-av/opmin@v0.4.0', 'with' => ['php' => '8.3']],
        ]);
        Assert::string($yaml)
            ->contains('#   docker run --rm -v "$PWD:/app" ghcr.io/melentev-av/opmin:0.4.0-php8.3 opmin baseline')
            ->contains('fails a pull request');
    }

    public function gitlabRunsThePinnedImageOnMergeRequests(): void
    {
        $yaml = CiWorkflow::render(CiSystem::Gitlab, '8.4', '1.2.3');

        /** @var array{opcodes: array{image: string, rules: list<array<string, string>>, variables: array<string, int>, script: list<string>, artifacts: array{reports: array<string, string>}}} $job */
        $job = Yaml::parse($yaml);
        Assert::same(\array_keys($job), ['opcodes']);
        Assert::same($job['opcodes']['image'], 'ghcr.io/melentev-av/opmin:1.2.3-php8.4');
        Assert::same($job['opcodes']['rules'], [['if' => '$CI_PIPELINE_SOURCE == "merge_request_event"']]);
        Assert::same($job['opcodes']['variables'], ['GIT_DEPTH' => 0]);
        Assert::same($job['opcodes']['script'], [
            'git fetch origin "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"',
            'opmin check --base="origin/$CI_MERGE_REQUEST_TARGET_BRANCH_NAME" --format=gitlab > gl-code-quality.json',
        ]);
        Assert::same($job['opcodes']['artifacts']['reports'], ['codequality' => 'gl-code-quality.json']);
        Assert::string($yaml)->contains('fails a merge request');
    }

    public function gitlabIncludeNamesTheFileOfTheJob(): void
    {
        Assert::same(Yaml::parse(CiWorkflow::gitlabInclude()), ['include' => [['local' => '.gitlab/opmin.yml']]]);
        Assert::same(CiSystem::Gitlab->file(), '.gitlab/opmin.yml');
        Assert::same(CiSystem::Github->file(), '.github/workflows/opcodes.yml');
    }

    public function baselineCommandUsesTheImageOfTheCheck(): void
    {
        Assert::same(
            CiWorkflow::baselineCommand('8.1', '0.4.0'),
            'docker run --rm -v "$PWD:/app" ghcr.io/melentev-av/opmin:0.4.0-php8.1 opmin baseline',
        );
    }
}
