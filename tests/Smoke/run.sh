#!/usr/bin/env bash
# Smoke test of opmin on a real package (brief, «Тестирование самой тулзы»; plan/M8): optimizes the package
# in git package mode, then runs the package's own tests on a fresh clone before and after the patch. The
# tests must be green both times: a red run after the patch is a bug of the verifier or of a rule.
#
#   tests/Smoke/run.sh <package> <php>         # package: see `packages` below; php: 8.1 … 8.5
#
# Environment:
#   OPMIN       opmin to run (default: bin/opmin from the sources; CI: the static binary)
#   SMOKE_IMAGE image with opmin and the PHP of the package, {php} is the PHP minor
#               (default: opmin:smoke-php{php}, see docker/Dockerfile)
#   SMOKE_DIR   working directory (default: ~/.cache/opmin-smoke — Docker VMs share only the home directory)
#
# Writes <SMOKE_DIR>/<package>-php<php>/: run.log, runs/ (report.md, report.json, counterexamples), the patch,
# and summary.json — one row of the summary table.
set -euo pipefail

package="${1:?package}"
php="${2:?php minor}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
opmin="${OPMIN:-$root/bin/opmin}"
image_template="${SMOKE_IMAGE:-opmin:smoke-php{php}}"
image="${image_template//\{php\}/$php}"
base="${SMOKE_DIR:-$HOME/.cache/opmin-smoke}"

# url, tag, extra options of `opmin optimize`, command that prepares the tests of a clone (network on),
# arguments of PHPUnit for both runs of the tests: under opmin and here.
phpunit_args=''
case "$package" in
  symfony-string)
    url=https://github.com/symfony/string.git; ref=v6.4.46
    # Symfony components take PHPUnit from the monorepo: the package itself has none, so opmin verifies with
    # the differential tests only, and PHPUnit is added here for the check.
    options=(--set=tests.runner=none)
    prepare='composer require --dev --no-interaction --no-progress phpunit/phpunit:^9.6'
    ;;
  league-csv)
    url=https://github.com/thephpleague/csv.git; ref=9.28.0
    # opmin runs the tests of a package without network: the tests of the group `network` cannot pass there.
    phpunit_args='--exclude-group=network'
    prepare='composer install --no-interaction --no-progress --ignore-platform-req=ext-xdebug'
    ;;
  carbon)
    url=https://github.com/briannesbitt/Carbon.git; ref=3.14.2
    # testSetLocaleToAutoFallback errors without the system locale fr_FR.UTF-8 (the image has only C).
    phpunit_args='--exclude-group=localization'
    prepare='composer install --no-interaction --no-progress'
    ;;
  *)
    echo "unknown package: $package" >&2
    exit 2
    ;;
esac

options=(${options[@]+"${options[@]}"})
[ -z "$phpunit_args" ] || options+=("--set=tests.command=vendor/bin/phpunit $phpunit_args")

work="$base/$package-php$php"
rm -rf "$work"
mkdir -p "$work"
cd "$work"

tests() { # <dir> <label>: the package's tests in the image, as the CI of the package runs them
  local dir="$1" label="$2"
  # stdin is a pipe, as under opmin: tests of non-seekable streams read php://stdin.
  mkdir -p "$base/.composer-cache"
  : | docker run --rm -i -v "$dir:/app" -w /app -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp \
    -v "$base/.composer-cache:/composer-cache" -e COMPOSER_CACHE_DIR=/composer-cache "$image" \
    sh -c "$prepare > /dev/null && vendor/bin/phpunit --colors=never $phpunit_args" > "$work/tests-$label.log" 2>&1
}

started=$(date +%s)
code=0
"$opmin" optimize "$url" --ref="$ref" --no-interaction \
  --set="package.docker_image=$image_template" --set="php.target=$php" ${options[@]+"${options[@]}"} > run.log 2>&1 || code=$?
seconds=$(( $(date +%s) - started ))
tail -n 40 run.log

patch="$(ls opmin-*.patch 2>/dev/null | head -n 1 || true)"
git -c advice.detachedHead=false clone -q --depth=1 --branch "$ref" "$url" "$work/check"
before=green
tests "$work/check" before || before=red
after=n/a
if [ -n "$patch" ]; then
  git -C "$work/check" checkout -q -- .
  git -C "$work/check" apply "$work/$patch"
  after=green
  tests "$work/check" after || after=red
fi

report="$(ls runs/*/report.json 2>/dev/null | head -n 1 || true)"
php -r '
  [, $file, $package, $ref, $php, $code, $seconds, $before, $after] = $argv;
  $r = $file !== "" && is_file($file) ? json_decode(file_get_contents($file), true) : null;
  $t = $r["totals"] ?? [];
  echo json_encode([
      "package" => $package, "ref" => $ref, "php" => $php, "exit" => (int) $code, "seconds" => (int) $seconds,
      "ops_before" => $t["ops_before"] ?? null, "ops_after" => $t["ops_after"] ?? null, "percent" => $t["percent"] ?? null,
      "functions_changed" => $t["functions_changed"] ?? null, "rolled_back" => $t["changes_rolled_back"] ?? null,
      "tests_before" => $before, "tests_after" => $after,
  ], JSON_UNESCAPED_SLASHES), "\n";
' "$report" "$package" "$ref" "$php" "$code" "$seconds" "$before" "$after" | tee summary.json

# opmin must finish, the tests must be green on the original (else the package or the tag is unfit) and
# after the patch.
[ "$code" -eq 0 ] || { echo "::error::$package php$php: opmin optimize exited with $code"; exit 1; }
[ "$before" = green ] || { echo "::error::$package php$php: the package's tests fail on the original code"; tail -n 30 "$work/tests-before.log"; exit 1; }
[ "$after" != red ] || { echo "::error::$package php$php: the package's tests fail after the patch"; tail -n 60 "$work/tests-after.log"; exit 1; }
