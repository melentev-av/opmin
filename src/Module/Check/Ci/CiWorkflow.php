<?php

declare(strict_types=1);

namespace Opmin\Module\Check\Ci;

use Opmin\Info;

/**
 * The job of the CI guard (`opmin check` on pull requests) for one CI system, one PHP minor and one opmin
 * release: the image `ghcr.io/melentev-av/opmin:<version>-php<minor>`, pinned — a floating tag would let a
 * counting fix of a newer opmin change the counts of an unchanged pull request.
 *
 * ```php
 * $yaml = CiWorkflow::render(CiSystem::Github, '8.3', '0.4.0');
 * ```
 *
 * @internal
 */
final class CiWorkflow
{
    /**
     * @param non-empty-string $php PHP minor, `8.3`.
     * @param non-empty-string $version opmin release, `0.4.0`.
     * @return non-empty-string
     */
    public static function render(CiSystem $system, string $php, string $version): string
    {
        $image = self::image($php, $version);
        $baseline = self::baselineCommand($php, $version);
        $request = $system === CiSystem::Gitlab ? 'merge request' : 'pull request';
        $header = <<<YAML
            # The CI guard of opmin (written by `opmin init --ci={$system->value}`): fails a {$request} whose
            # PHP functions grew in opcodes against opmin.baseline.json. PHP {$php} must be the PHP minor of
            # production. The baseline is taken with the same image; create it and commit opmin.baseline.json:
            #   {$baseline}
            # Bump the opmin version on purpose, together with the baseline: counting fixes may change counts.


            YAML;

        return $header . match ($system) {
            CiSystem::Github => <<<YAML
                name: Opcodes

                on: pull_request

                permissions:
                  contents: read

                jobs:
                  opmin:
                    runs-on: ubuntu-latest  # the action runs the image with docker: a Linux runner
                    steps:
                      - uses: actions/checkout@v5
                        with:
                          fetch-depth: 0  # the base branch is needed for the changed-files mode
                      - uses: melentev-av/opmin@v{$version}
                        with:
                          php: '{$php}'

                YAML,
            CiSystem::Gitlab => <<<YAML
                opcodes:
                  stage: test
                  image: {$image}
                  rules:
                    - if: \$CI_PIPELINE_SOURCE == "merge_request_event"
                  variables:
                    GIT_DEPTH: 0
                  script:
                    - git fetch origin "\$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
                    - opmin check --base="origin/\$CI_MERGE_REQUEST_TARGET_BRANCH_NAME" --format=gitlab > gl-code-quality.json
                  artifacts:
                    when: always
                    reports:
                      codequality: gl-code-quality.json

                YAML,
        };
    }

    /**
     * @param non-empty-string $php
     * @param non-empty-string $version
     * @return non-empty-string
     */
    public static function image(string $php, string $version): string
    {
        return 'ghcr.io/' . Info::REPOSITORY . ":{$version}-php{$php}";
    }

    /**
     * The command that takes the baseline with the image of the check, run in the project root.
     *
     * @param non-empty-string $php
     * @param non-empty-string $version
     * @return non-empty-string
     */
    public static function baselineCommand(string $php, string $version): string
    {
        return 'docker run --rm -v "$PWD:/app" ' . self::image($php, $version) . ' opmin baseline';
    }

    /**
     * What `.gitlab-ci.yml` needs to run the job of {@see CiSystem::Gitlab}.
     *
     * @return non-empty-string
     */
    public static function gitlabInclude(): string
    {
        return "include:\n  - local: " . CiSystem::Gitlab->file();
    }
}
